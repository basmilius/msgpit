<?php

declare(strict_types=1);

namespace Msgpit\Core;

/**
 * GSM 03.38 encoding detection and segment counting.
 *
 * A single character outside the GSM-7 alphabet pushes the whole message to UCS-2 and so cuts
 * its capacity from 160 to 70. Making that visible is the point of this class.
 */
final class Segments
{
    /** GSM 03.38 default alphabet. ESC (0x1B) is omitted: it only introduces an extension. */
    private const GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Extension table: each of these costs two septets, because ESC precedes them. */
    private const GSM7_EXTENDED = "\f^{}\\[~]|€";

    private const GSM7_SINGLE = 160;
    private const GSM7_CONCATENATED = 153;
    private const UCS2_SINGLE = 70;
    private const UCS2_CONCATENATED = 67;

    public static function analyze(string $text): SegmentInfo
    {
        $characters = self::split($text);
        $basic = self::split(self::GSM7_BASIC);
        $extended = self::split(self::GSM7_EXTENDED);

        $ucs2Offsets = [];
        $septets = 0;

        foreach ($characters as $offset => $character) {
            if (in_array($character, $basic, true)) {
                $septets += 1;
            } elseif (in_array($character, $extended, true)) {
                $septets += 2;
            } else {
                $ucs2Offsets[] = $offset;
            }
        }

        if ($ucs2Offsets !== []) {
            return self::asUcs2($characters, $ucs2Offsets);
        }

        $costs = array_map(
            static fn (string $character): int => in_array($character, $extended, true) ? 2 : 1,
            $characters,
        );

        return new SegmentInfo(
            encoding: Encoding::Gsm7,
            segments: self::count($costs, self::GSM7_SINGLE, self::GSM7_CONCATENATED),
            characters: count($characters),
            units: $septets,
        );
    }

    /**
     * @param list<string> $characters
     * @param list<int> $ucs2Offsets
     */
    private static function asUcs2(array $characters, array $ucs2Offsets): SegmentInfo
    {
        // A 4-byte UTF-8 sequence is outside the BMP, so UTF-16 needs a surrogate pair for it.
        $costs = array_map(
            static fn (string $character): int => strlen($character) === 4 ? 2 : 1,
            $characters,
        );

        return new SegmentInfo(
            encoding: Encoding::Ucs2,
            segments: self::count($costs, self::UCS2_SINGLE, self::UCS2_CONCATENATED),
            characters: count($characters),
            units: array_sum($costs),
            ucs2Offsets: $ucs2Offsets,
        );
    }

    /**
     * Fills segments without ever splitting a two-unit character, which is what real
     * concatenation does and what makes the count differ from a plain division.
     *
     * @param list<int> $costs
     */
    private static function count(array $costs, int $single, int $concatenated): int
    {
        if ($costs === []) {
            return 0;
        }

        if (array_sum($costs) <= $single) {
            return 1;
        }

        $segments = 1;
        $used = 0;

        foreach ($costs as $cost) {
            if ($used + $cost > $concatenated) {
                $segments++;
                $used = $cost;

                continue;
            }

            $used += $cost;
        }

        return $segments;
    }

    /** @return list<string> */
    private static function split(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $characters === false ? [] : $characters;
    }
}
