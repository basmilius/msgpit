<?php

declare(strict_types=1);

namespace Msgpit\Core;

use Closure;
use Msgpit\Http\Request;

final readonly class Route
{
    /**
     * @param string $pattern Path after the provider prefix, with {name} placeholders.
     * @param Closure(Request, array<string, string>): Capture $handler
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public Closure $handler,
    ) {}

    /** @return array<string, string>|null Path parameters, or null when the route does not match. */
    public function match(string $method, string $path): ?array
    {
        if (strcasecmp($method, $this->method) !== 0) {
            return null;
        }

        // Quote first, then swap the placeholders: preg_quote escapes the braces too.
        $quoted = preg_quote($this->pattern, '#');
        $regex = '#^' . preg_replace('/\\\\\{(\w+)\\\\}/', '(?P<$1>[^/]+)', $quoted) . '$#';

        if (preg_match($regex, $path, $matches) !== 1) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
