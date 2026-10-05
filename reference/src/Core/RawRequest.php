<?php

declare(strict_types=1);

namespace Msgpit\Core;

use Msgpit\Http\Request;

final readonly class RawRequest
{
    private const SECRET_HEADERS = [
        'authorization',
        'x-api-key',
        'api-key',
        'proxy-authorization',
        'cookie',
    ];

    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers,
        public string $body,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $headers = [];

        foreach ($request->headers as $name => $value) {
            $headers[$name] = in_array(strtolower($name), self::SECRET_HEADERS, true)
                ? self::mask($value)
                : $value;
        }

        return new self($request->method, $request->path, $headers, $request->body);
    }

    /** Keeps enough to recognise a key, not enough to use it. */
    private static function mask(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
    }

    /**
     * The message on its own, without the line naming how it arrived. For mail that is the whole
     * thing: headers and all, exactly as the sender wrote it.
     */
    public static function messageFrom(string $rendered): string
    {
        $position = strpos($rendered, "\n\n");

        return $position === false ? $rendered : substr($rendered, $position + 2);
    }

    /** Rendered as a raw HTTP request, which is what a developer recognises. */
    public function toText(): string
    {
        $lines = ["{$this->method} {$this->path} HTTP/1.1"];

        foreach ($this->headers as $name => $value) {
            $lines[] = ucwords($name, '-') . ": {$value}";
        }

        return implode("\n", $lines) . "\n\n" . $this->body;
    }
}
