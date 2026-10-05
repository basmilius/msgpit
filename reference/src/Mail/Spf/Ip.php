<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

/** Address arithmetic on packed addresses, so a prefix is compared as bits and never as text. */
final class Ip
{
    /** Null when the literal is not an address. IPv4-mapped IPv6 becomes plain IPv4. */
    public static function parse(string $address): ?string
    {
        $packed = @inet_pton(trim($address));

        if ($packed === false) {
            return null;
        }

        // ::ffff:192.0.2.1 is the same host as 192.0.2.1, and an ip4: mechanism has to match it.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    public static function parseFour(string $address): ?string
    {
        $packed = self::parse($address);

        return $packed !== null && strlen($packed) === 4 ? $packed : null;
    }

    public static function parseSix(string $address): ?string
    {
        // An ip6: mechanism must carry an IPv6 literal, even where it denotes a mapped IPv4 range.
        if (!str_contains($address, ':')) {
            return null;
        }

        return self::parse($address);
    }

    public static function bits(string $packed): int
    {
        return strlen($packed) * 8;
    }

    /** Both addresses must be of the same family; anything else cannot match by definition. */
    public static function matches(string $client, string $network, int $prefix): bool
    {
        if (strlen($client) !== strlen($network) || $prefix < 0 || $prefix > self::bits($client)) {
            return false;
        }

        $whole = intdiv($prefix, 8);

        if (substr($client, 0, $whole) !== substr($network, 0, $whole)) {
            return false;
        }

        $rest = $prefix % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = chr((0xff << (8 - $rest)) & 0xff);

        return ($client[$whole] & $mask) === ($network[$whole] & $mask);
    }
}
