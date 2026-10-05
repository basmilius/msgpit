#!/usr/bin/env python3
import json
import os
import re
import subprocess
import time
import uuid

image = os.environ.get('MSGPIT_IMAGE', 'msgpit:local')
prefix = f'msgpit-runtime-{uuid.uuid4().hex[:8]}'
containers = []


def docker(*args, check=True):
    return subprocess.run(['docker', *args], check=check, capture_output=True, text=True)


def state(name):
    return json.loads(docker('inspect', name).stdout)[0]['State']


def wait_for(name, predicate, seconds=45):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        current = state(name)
        if predicate(current):
            return current
        time.sleep(0.2)
    raise AssertionError(f'{name}: {state(name)}\n{docker("logs", name).stdout}')


def start(suffix, *args):
    name = f'{prefix}-{suffix}'
    docker('run', '-d', '--name', name, '--network', 'none', '--tmpfs',
           '/data:uid=10001,gid=10001', *args, image)
    containers.append(name)
    return name


def healthy(name):
    return wait_for(name, lambda s: s.get('Health', {}).get('Status') == 'healthy')


def http(name, method, path, body=''):
    client = r'''use IO::Socket::INET;
my ($method, $path, $body) = @ARGV;
my $socket = IO::Socket::INET->new(PeerAddr => '127.0.0.1', PeerPort => 8080, Timeout => 10) or die $!;
print $socket "$method $path HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nContent-Type: message/rfc822\r\nContent-Length: " . length($body) . "\r\n\r\n$body";
print while <$socket>;'''
    response = docker('exec', name, 'perl', '-e', client, method, path, body).stdout
    headers, data = response.replace('\r\n', '\n').split('\n\n', 1)
    assert re.match(r'HTTP/1.1 20[01]', headers), response
    return json.loads(data)


def spamd_pid(name):
    logs = docker('logs', name).stdout
    logs = re.sub(r'\x1b\[[0-9;]*m', '', logs)
    return re.findall(r'Bundled SpamAssassin ready.*?pid\s*=\s*(\d+)', logs)[-1]


try:
    default = start('default', '-e', 'NO_COLOR=1')
    healthy(default)
    assert docker('exec', default, 'id', '-u').stdout.strip() == '10001'
    assert json.loads(docker('inspect', default).stdout)[0]['HostConfig'].get('Init') is not True
    mail = ('From: sender@example.org\r\nTo: recipient@example.org\r\nSubject: Offline GTUBE\r\n'
            'MIME-Version: 1.0\r\nContent-Type: text/plain\r\n\r\n'
            'XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X\r\n')
    assert http(default, 'POST', '/api/messages/import', mail)['imported'] == 1
    captured = http(default, 'GET', '/api/messages')['messages'][0]
    assert captured['meta']['spam']['spam'] is True
    assert any(r['name'] == 'GTUBE' for r in captured['meta']['spam']['rules'])

    docker('exec', default, 'perl', '-e', "kill 'STOP', -$ARGV[0] or die $!", spamd_pid(default))
    assert docker('exec', default, 'msgpit', 'healthcheck', check=False).returncode != 0
    docker('exec', default, 'perl', '-e', "kill 'CONT', -$ARGV[0] or die $!", spamd_pid(default))
    docker('stop', '--time', '10', default)
    assert state(default)['ExitCode'] == 0, state(default)
    docker('start', default)
    healthy(default)
    assert docker('exec', default, 'msgpit', 'healthcheck').returncode == 0
    docker('exec', default, 'perl', '-e', "kill 'KILL', $ARGV[0] or die $!", spamd_pid(default))
    stopped = wait_for(default, lambda s: not s['Running'], seconds=10)
    assert stopped['ExitCode'] == 1, stopped
    assert 'SpamAssassin exited unexpectedly' in docker('logs', default).stderr

    recovery = start('recovery', '--restart', 'on-failure:1', '-e', 'NO_COLOR=1')
    healthy(recovery)
    docker('exec', recovery, 'perl', '-e', "kill 'KILL', $ARGV[0] or die $!", spamd_pid(recovery))
    wait_for(recovery, lambda s: s.get('Health', {}).get('Status') == 'healthy'
             and json.loads(docker('inspect', recovery).stdout)[0]['RestartCount'] == 1)
    assert http(recovery, 'POST', '/api/messages/import', mail)['imported'] == 1
    assert http(recovery, 'GET', '/api/messages')['messages'][0]['meta']['spam']['spam'] is True

    disabled = start('off', '-e', 'MSGPIT_SPAMASSASSIN=off', '-e', 'MSGPIT_SMTP=0')
    healthy(disabled)
    assert http(disabled, 'POST', '/api/messages/import', mail)['imported'] == 1
    listing = http(disabled, 'GET', '/api/messages')['messages']
    assert listing[0]['meta'].get('spam') is None
    assert 'Bundled SpamAssassin ready' not in docker('logs', disabled).stdout
    docker('stop', '--time', '10', disabled)
    assert state(disabled)['ExitCode'] == 0

    external = start('external', '-e', 'MSGPIT_SPAMASSASSIN=127.0.0.1:1783')
    healthy(external)
    assert http(external, 'POST', '/api/messages/import', mail)['imported'] == 1
    assert 'Bundled SpamAssassin ready' not in docker('logs', external).stdout

    missing = start('missing', '-e', 'PATH=/usr/local/bin')
    stopped = wait_for(missing, lambda s: not s['Running'], seconds=10)
    assert stopped['ExitCode'] == 1
    assert 'Could not start bundled SpamAssassin' in docker('logs', missing).stderr
    print('PASS: plain docker run without network, non-root scoring, health failure, stop/start, daemon crash, disabled/external modes and startup failure')
finally:
    for name in containers:
        docker('rm', '-f', name, check=False)
