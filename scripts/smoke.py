#!/usr/bin/env python3
import json
import os
import re
import smtplib
import urllib.error
import urllib.request
import uuid
from email.message import EmailMessage
from email.utils import formatdate, make_msgid

base = os.environ.get('MSGPIT_URL', 'http://localhost:18080').rstrip('/')
smtp_port = int(os.environ.get('MSGPIT_SMTP_PORT', '11025'))
tag = f'smoke-{uuid.uuid4().hex[:8]}'


def call(path, method='GET', payload=None, headers=None):
    data = None if payload is None else json.dumps(payload).encode()
    request_headers = {'Content-Type': 'application/json', **(headers or {})}
    request = urllib.request.Request(base + path, data=data, method=method, headers=request_headers)
    try:
        response = urllib.request.urlopen(request, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        body = response.read()
        return response.status, json.loads(body) if body else None


assert call('/healthz')[1]['status'] == 'ok'
with urllib.request.urlopen(base + '/', timeout=10) as response:
    html = response.read().decode()
    assert response.status == 200
    asset = re.search(r'src="(/assets/[^"]+\.js)"', html).group(1)
with urllib.request.urlopen(base + asset, timeout=10) as response:
    assert 'javascript' in response.headers['Content-Type']
    assert len(response.read()) > 1000

payload = {'accountReference': 'SPNL0000000', 'channel': 'SMS', 'from': 'msgpit smoke',
           'body': {'text': f'Hello from the Rust container 👋 ({tag})'},
           'recipients': [{'msisdn': '+31612345678'}, {'msisdn': '+31612345679'}]}
status, sent = call('/spryng/v2/messages', 'POST', payload, {'X-Api-Key': 'smoke-secret-do-not-store'})
assert status == 202 and len(sent['data']['messageIds']) == 2
_, listing = call('/api/messages?channel=sms')
messages = [m for m in listing['messages'] if tag in m['body']]
assert len(messages) == 2 and messages[0]['batchId'] == messages[1]['batchId']
assert all(m['encoding'] == 'UCS-2' and m['segments'] >= 1 for m in messages)
_, detail = call('/api/messages/' + messages[0]['id'])
assert 'smoke-secret-do-not-store' not in detail['rawRequest'] and '[redacted]' in detail['rawRequest']
assert call('/api/messages/' + messages[0]['id'] + '/read', 'POST')[0] == 200

for number, expected in [('+31600000001', 400), ('+31600000002', 401), ('+31600000003', 429), ('+31600000004', 500)]:
    failed = {**payload, 'recipients': [{'msisdn': number}]}
    assert call('/spryng/v2/messages', 'POST', failed, {'X-Api-Key': 'smoke'})[0] == expected

email = EmailMessage()
email['From'] = 'Msgpit <sender@example.test>'
email['To'] = 'visible@example.test'
email['Date'] = formatdate(localtime=True)
email['Message-ID'] = make_msgid(domain='example.org')
email['Subject'] = f'A captured email from Docker ({tag})'
email.set_content('SMTP capture is working. The hidden recipient comes from the envelope.')
email.add_alternative('<html><body><h1>Hello from msgpit</h1><p>SMTP and HTML previews are working.</p></body></html>', subtype='html')
email.add_attachment(b'Captured attachment\n', maintype='text', subtype='plain', filename='example.txt')
with smtplib.SMTP('localhost', smtp_port, timeout=10) as client:
    client.send_message(email, from_addr='sender@example.test', to_addrs=['hidden@example.test'])
_, listing = call('/api/messages?channel=email')
mail = next(m for m in listing['messages'] if tag in m['meta'].get('subject', ''))
assert mail['to'] == 'hidden@example.test' and mail['provider'] == 'smtp'
_, detail = call('/api/messages/' + mail['id'])
assert 'Hello from msgpit' in detail['html']
assert detail['spamConfigured'] and detail['spam'] is not None
assert detail['spam']['spam'] is False, detail['spam']
assert detail['spam']['score'] < detail['spam']['threshold']
assert any(h['name'] == 'From' and 'sender@example.test' in h['value'] for h in detail['headerList'])
attachment = next(p for p in detail['parts'] if p['filename'] == 'example.txt')
with urllib.request.urlopen(base + '/api/messages/' + mail['id'] + '/parts/' + attachment['id']) as response:
    assert response.read() == b'Captured attachment\n'
    assert response.headers['X-Content-Type-Options'] == 'nosniff'

request = urllib.request.Request(base + '/api/messages/import', data=email.as_bytes(), method='POST',
    headers={'Content-Type': 'message/rfc822', 'X-Msgpit-Filename': 'smoke.eml'})
with urllib.request.urlopen(request, timeout=10) as response:
    assert response.status == 201
    imported = json.load(response)['imported']
    assert imported == 1

gtube = EmailMessage()
gtube['From'] = 'sender@example.org'
gtube['To'] = 'recipient@example.org'
gtube['Subject'] = f'GTUBE spam filter test ({tag})'
gtube['Date'] = formatdate(localtime=True)
gtube['Message-ID'] = make_msgid(domain='example.org')
gtube.set_content('XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X')
with smtplib.SMTP('localhost', smtp_port, timeout=15) as client:
    client.send_message(gtube)
request = urllib.request.Request(base + '/api/messages/import', data=gtube.as_bytes(), method='POST',
    headers={'Content-Type': 'message/rfc822', 'X-Msgpit-Filename': 'gtube.eml'})
with urllib.request.urlopen(request, timeout=15) as response:
    assert response.status == 201
_, listing = call('/api/messages?channel=email')
spam_messages = [m for m in listing['messages'] if m['meta'].get('subject') == gtube['Subject']]
assert len(spam_messages) == 2
for message in spam_messages:
    _, detail = call('/api/messages/' + message['id'])
    spam = detail['spam']
    assert spam is not None and spam['spam'] is True, spam
    assert spam['score'] >= 1000 and spam['score'] > spam['threshold'], spam
    assert any(rule['name'] == 'GTUBE' for rule in spam['rules']), spam

with urllib.request.urlopen(base + '/api/stream?seq=0', timeout=10) as response:
    first = response.readline().decode().strip()
    assert first.startswith('id: ')
    sequence = int(first.split(':', 1)[1])
with urllib.request.urlopen(urllib.request.Request(base + '/api/stream', headers={'Last-Event-ID': str(sequence)}), timeout=10) as response:
    assert int(response.readline().decode().strip().split(':', 1)[1]) > sequence

print(f'PASS: HTTP, bundled UI, SMS, failures, read state, SMTP, MIME, attachment, import, real SpamAssassin (clean + GTUBE via SMTP/import) and SSE ({tag})')
print(f'Browser: {base}')
