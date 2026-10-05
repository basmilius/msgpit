<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Encoding;
use Msgpit\Core\Segments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SegmentsTest extends TestCase
{
    #[DataProvider('gsm7Cases')]
    public function testItCountsGsm7Segments(string $text, int $segments, int $units): void
    {
        $info = Segments::analyze($text);

        self::assertSame(Encoding::Gsm7, $info->encoding);
        self::assertSame($segments, $info->segments);
        self::assertSame($units, $info->units);
        self::assertSame([], $info->ucs2Offsets);
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function gsm7Cases(): iterable
    {
        yield 'short' => ['Hello', 1, 5];
        yield 'exactly one segment' => [str_repeat('a', 160), 1, 160];
        yield 'one over' => [str_repeat('a', 161), 2, 161];
        yield 'exactly two segments' => [str_repeat('a', 306), 2, 306];
        yield 'one over two segments' => [str_repeat('a', 307), 3, 307];
        yield 'accented latin in the gsm7 table' => ['Café über Ñandu', 1, 15];
        yield 'newlines are gsm7' => ["line\nline\r", 1, 10];
    }

    #[DataProvider('extensionCases')]
    public function testExtensionCharactersCostTwoSeptets(string $text, int $characters, int $units): void
    {
        $info = Segments::analyze($text);

        self::assertSame(Encoding::Gsm7, $info->encoding);
        self::assertSame($characters, $info->characters);
        self::assertSame($units, $info->units);
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function extensionCases(): iterable
    {
        yield 'euro sign' => ['€', 1, 2];
        yield 'braces' => ['{}', 2, 4];
        yield 'brackets and pipe' => ['[~]|', 4, 8];
        yield 'caret and backslash' => ['^\\', 2, 4];
        yield 'mixed' => ['Prijs: 10€', 10, 11];
    }

    public function testAnExtensionCharacterIsNeverSplitAcrossSegments(): void
    {
        // 152 plain septets leaves one free, too little for the two-septet euro sign,
        // so it moves to the next segment instead of being split.
        $info = Segments::analyze(str_repeat('a', 152) . '€' . str_repeat('a', 152));

        self::assertSame(Encoding::Gsm7, $info->encoding);
        self::assertSame(306, $info->units);
        self::assertSame(3, $info->segments);
    }

    /** @param list<int> $offsets */
    /** @param list<int> $offsets */
    #[DataProvider('ucs2Cases')]
    public function testItDetectsUcs2(string $text, int $segments, int $units, array $offsets): void
    {
        $info = Segments::analyze($text);

        self::assertSame(Encoding::Ucs2, $info->encoding);
        self::assertSame($segments, $info->segments);
        self::assertSame($units, $info->units);
        self::assertSame($offsets, $info->ucs2Offsets);
    }

    /** @return iterable<string, array{string, int, int, list<int>}> */
    public static function ucs2Cases(): iterable
    {
        yield 'emoji outside the bmp counts double' => ['Hi 👋', 1, 5, [3]];
        yield 'cyrillic' => ['Привет', 1, 6, [0, 1, 2, 3, 4, 5]];
        yield 'one offender in a long ascii text' => ['a' . str_repeat('b', 68) . '☂', 1, 70, [69]];
        yield 'just over one segment' => ['a' . str_repeat('b', 69) . '☂', 2, 71, [70]];
        yield 'arrow' => ['Go →', 1, 4, [3]];
        // These look like their GSM-7 neighbours but are not in the table.
        yield 'u with acute is not gsm7' => ['Ñandú', 1, 5, [4]];
    }

    public function testASurrogatePairIsNeverSplitAcrossSegments(): void
    {
        // 66 units used leaves one free, too little for the surrogate pair.
        $info = Segments::analyze(str_repeat('a', 66) . '😀' . str_repeat('a', 66));

        self::assertSame(Encoding::Ucs2, $info->encoding);
        self::assertSame(134, $info->units);
        self::assertSame(3, $info->segments);
    }

    public function testItReportsEveryOffendingOffset(): void
    {
        $info = Segments::analyze('a☂b☂');

        self::assertSame([1, 3], $info->ucs2Offsets);
        self::assertSame(4, $info->characters);
    }

    public function testAnEmptyBodyHasNoSegments(): void
    {
        $info = Segments::analyze('');

        self::assertSame(0, $info->segments);
        self::assertSame(0, $info->characters);
        self::assertSame(Encoding::Gsm7, $info->encoding);
    }
}
