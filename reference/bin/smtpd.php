<?php

declare(strict_types=1);

/**
 * The SMTP listener, run beside the web server by docker-entrypoint.sh.
 *
 * It writes to the same SQLite file as the web process, which is why Storage runs in WAL mode.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Msgpit\Core\MailCapture;
use Msgpit\Core\SpamAssassin;
use Msgpit\Core\Storage;
use Msgpit\Smtp\Envelope;
use Msgpit\Smtp\Server;

$env = static fn (string $name, string $default): string => is_string($value = getenv($name)) && $value !== ''
    ? $value
    : $default;

if ($env('MSGPIT_SMTP', '1') === '0') {
    fwrite(STDERR, "smtp: disabled through MSGPIT_SMTP=0\n");

    exit(0);
}

$storage = Storage::open($env('MSGPIT_DB', '/data/msgpit.sqlite'), (int) $env('MSGPIT_MAX_MESSAGES', '1000'));
$spamAssassin = SpamAssassin::fromEnvironment();
$capture = new MailCapture($storage, $spamAssassin);

if ($spamAssassin !== null) {
    fwrite(STDERR, 'smtp: scoring through spamassassin at ' . getenv('MSGPIT_SPAMASSASSIN') . "\n");
}
$port = (int) $env('MSGPIT_SMTP_PORT', '1025');

$server = new Server(
    address: "tcp://0.0.0.0:{$port}",
    onMessage: static function (Envelope $envelope) use ($capture): void {
        try {
            $capture->capture($envelope);
        } catch (\Throwable $exception) {
            // A message we cannot store must not take the listener down with it.
            fwrite(STDERR, 'smtp: could not store message: ' . $exception->getMessage() . "\n");
        }
    },
    hostname: $env('MSGPIT_SMTP_HOSTNAME', 'msgpit'),
);

fwrite(STDERR, "smtp: listening on {$port}\n");

$server->run();
