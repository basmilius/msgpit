<?php

declare(strict_types=1);

namespace Msgpit\Http;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = [],
    ) {}

    /** @param array<string, mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            status: $status,
            body: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            headers: ['Content-Type' => 'application/json'],
        );
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
    }
}
