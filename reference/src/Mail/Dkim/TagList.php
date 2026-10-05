<?php

declare(strict_types=1);

namespace Msgpit\Mail\Dkim;

/** The "tag=value; tag=value" grammar of RFC 6376 3.2, shared by the signature and the key record. */
final class TagList
{
    /**
     * @return array<string, string>|null Null when a tag is unnamed or repeated, which RFC 6376 forbids.
     */
    public static function parse(string $input): ?array
    {
        $tags = [];

        foreach (explode(';', $input) as $part) {
            if (trim($part) === '') {
                continue;
            }

            $split = explode('=', $part, 2);

            if (count($split) !== 2) {
                return null;
            }

            $name = trim($split[0]);

            if ($name === '' || isset($tags[$name])) {
                return null;
            }

            $tags[$name] = trim($split[1]);
        }

        return $tags;
    }

    /** Base64 and colon-separated values may be folded anywhere, so whitespace never carries meaning. */
    public static function compact(string $value): string
    {
        return preg_replace('/[\s]+/', '', $value) ?? $value;
    }
}
