<?php

declare(strict_types=1);

namespace Msgpit\Http;

/** A callback msgpit sends back to the app. The only outbound HTTP it ever makes. */
final readonly class OutgoingRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public string $body = '',
    ) {}
}
