<?php

declare(strict_types=1);

namespace Msgpit\Api;

use Msgpit\Core\Storage;
use Msgpit\Http\Request;

/**
 * SSE stream of captured messages, so the UI shows them the moment they arrive.
 * Holds a server worker for as long as the tab is open, hence PHP_CLI_SERVER_WORKERS=16.
 */
final readonly class Stream
{
    private const POLL_INTERVAL_US = 250_000;
    private const KEEPALIVE_SECONDS = 20;

    public function __construct(private Storage $storage) {}

    public function send(Request $request): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $seq = (int) ($request->query['seq'] ?? 0);
        $lastKeepalive = time();

        // The client reconnects on its own, so a bounded lifetime keeps workers from leaking.
        $deadline = time() + 3600;

        while (!connection_aborted() && time() < $deadline) {
            foreach ($this->storage->eventsSince($seq) as $event) {
                $seq = $event['seq'];
                $payload = ['type' => $event['type'], 'seq' => $seq];

                if ($event['messageId'] !== null) {
                    $payload['message'] = $this->storage->find($event['messageId'])?->toArray();
                }

                echo 'id: ', $seq, "\n";
                echo 'data: ', json_encode($payload, JSON_THROW_ON_ERROR), "\n\n";
                $lastKeepalive = time();
            }

            if (time() - $lastKeepalive >= self::KEEPALIVE_SECONDS) {
                echo ": keepalive\n\n";
                $lastKeepalive = time();
            }

            flush();
            usleep(self::POLL_INTERVAL_US);
        }
    }
}
