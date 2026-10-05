<?php

declare(strict_types=1);

namespace Msgpit\Http;

final readonly class Request
{
    /**
     * @param array<string, string> $headers Header names lowercased.
     * @param array<string, string> $query
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public array $query = [],
        public string $body = '',
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $headers[$name] = $_SERVER[$key];
            }
        }

        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);

        /** @var array<string, string> $query */
        $query = array_filter($_GET, 'is_string');

        return new self(
            method: is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            path: is_string($path) ? $path : '/',
            headers: $headers,
            query: $query,
            body: (string) file_get_contents('php://input'),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> Empty when the body is absent or not a JSON object. */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
