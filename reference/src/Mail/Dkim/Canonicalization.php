<?php

declare(strict_types=1);

namespace Msgpit\Mail\Dkim;

/** RFC 6376 3.4. Both halves of a c= tag, each canonicalizing headers and bodies its own way. */
enum Canonicalization: string
{
    case Simple = 'simple';
    case Relaxed = 'relaxed';

    /** One header, without its trailing CRLF: the caller decides whether to add one. */
    public function header(string $raw): string
    {
        if ($this === self::Simple) {
            return $raw;
        }

        $colon = strpos($raw, ':');

        if ($colon === false) {
            return strtolower(trim($raw)) . ':';
        }

        // Unfolding drops the CRLF but keeps its whitespace, which then collapses with the rest.
        $value = str_replace("\r\n", '', substr($raw, $colon + 1));
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;

        return strtolower(trim(substr($raw, 0, $colon))) . ':' . trim($value);
    }

    public function body(string $body): string
    {
        $lines = explode("\r\n", $body);

        if ($this === self::Relaxed) {
            foreach ($lines as $index => $line) {
                $lines[$index] = rtrim(preg_replace('/[ \t]+/', ' ', $line) ?? $line, " \t");
            }
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        if ($lines === []) {
            // Simple canonicalizes an empty body to a bare CRLF, relaxed to nothing at all.
            return $this === self::Relaxed ? '' : "\r\n";
        }

        return implode("\r\n", $lines) . "\r\n";
    }
}
