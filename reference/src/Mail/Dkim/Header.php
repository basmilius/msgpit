<?php

declare(strict_types=1);

namespace Msgpit\Mail\Dkim;

/** One header field as it sits in the message: folding, casing and whitespace intact. */
final readonly class Header
{
    public function __construct(
        public string $name,
        public string $raw,
    ) {}

    /** @return list<self> */
    public static function all(string $head): array
    {
        $headers = [];
        $current = null;

        foreach (explode("\r\n", $head) as $line) {
            if ($current !== null && $line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $current .= "\r\n" . $line;
                continue;
            }

            $headers[] = $current;
            $current = $line;
        }

        $headers[] = $current;
        $fields = [];

        foreach ($headers as $raw) {
            $colon = $raw === null ? false : strpos($raw, ':');

            if ($raw !== null && $colon !== false) {
                $fields[] = new self(strtolower(trim(substr($raw, 0, $colon))), $raw);
            }
        }

        return $fields;
    }
}
