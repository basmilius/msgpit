<?php

declare(strict_types=1);

namespace Msgpit\Core;

use Msgpit\Http\OutgoingRequest;

/**
 * Sends delivery-report callbacks. This is the only outbound HTTP msgpit makes.
 * Uses streams rather than ext-curl so the image needs no extra extensions.
 */
final readonly class DlrDispatcher
{
    public function __construct(
        private Storage $storage,
        private int $timeout = 5,
    ) {}

    public function dispatch(Message|string $message, DeliveryStatus $status, ?OutgoingRequest $callback): void
    {
        $messageId = $message instanceof Message ? $message->id : $message;

        $this->storage->updateStatus($messageId, $status->toMessageStatus());

        if ($callback === null) {
            return;
        }

        [$responseStatus, $responseBody] = $this->send($callback);

        $this->storage->recordDeliveryReport(
            messageId: $messageId,
            status: $status,
            url: $callback->url,
            requestBody: $callback->body,
            responseStatus: $responseStatus,
            responseBody: $responseBody,
        );
    }

    /** @return array{0: ?int, 1: ?string} */
    private function send(OutgoingRequest $request): array
    {
        $headers = '';

        foreach ($request->headers as $name => $value) {
            $headers .= "{$name}: {$value}\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $request->method,
                'header' => $headers,
                'content' => $request->body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($request->url, false, $context);

        if ($body === false) {
            return [null, 'Callback could not be delivered.'];
        }

        return [self::statusFromHeaders($http_response_header), $body];
    }

    /** @param array<int, string> $headers */
    private static function statusFromHeaders(array $headers): ?int
    {
        if (($headers[0] ?? null) === null) {
            return null;
        }

        preg_match('#HTTP/\S+\s+(\d{3})#', $headers[0], $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }
}
