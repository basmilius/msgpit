<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Mail\Spf\Ip;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpfIpTest extends TestCase
{
    /** @return list<array{string, string, int, bool}> */
    public static function prefixes(): array
    {
        return [
            ['192.0.2.10', '192.0.2.10', 32, true],
            ['192.0.2.10', '192.0.2.11', 32, false],
            ['192.0.2.255', '192.0.2.0', 24, true],
            ['192.0.3.0', '192.0.2.0', 24, false],
            ['192.0.2.10', '192.0.2.0', 0, true],
            ['10.0.0.1', '192.0.2.0', 0, true],
            ['192.0.2.130', '192.0.2.128', 25, true],
            ['192.0.2.127', '192.0.2.128', 25, false],
            ['2001:db8::1', '2001:db8::', 32, true],
            ['2001:db9::1', '2001:db8::', 32, false],
            ['2001:db8::ffff', '2001:db8::', 128, false],
            ['2001:db8:0:1::1', '2001:db8::', 64, false],
            ['2001:db8:0:1::1', '2001:db8::', 47, true],
        ];
    }

    #[DataProvider('prefixes')]
    public function testItComparesOnBits(string $client, string $network, int $prefix, bool $expected): void
    {
        $packedClient = Ip::parse($client);
        $packedNetwork = Ip::parse($network);

        self::assertNotNull($packedClient);
        self::assertNotNull($packedNetwork);
        self::assertSame($expected, Ip::matches($packedClient, $packedNetwork, $prefix));
    }

    public function testItUnwrapsAnIpv4MappedAddress(): void
    {
        self::assertSame(Ip::parse('192.0.2.10'), Ip::parse('::ffff:192.0.2.10'));
        self::assertSame(4, strlen((string) Ip::parse('::ffff:192.0.2.10')));
    }

    public function testFamiliesNeverMatchEachOther(): void
    {
        $four = Ip::parse('192.0.2.10');
        $six = Ip::parse('2001:db8::1');

        self::assertNotNull($four);
        self::assertNotNull($six);
        self::assertFalse(Ip::matches($six, $four, 0));
        self::assertFalse(Ip::matches($four, $six, 0));
    }

    public function testItRejectsWhatIsNotAnAddress(): void
    {
        self::assertNull(Ip::parse('example.test'));
        self::assertNull(Ip::parse('192.0.2'));
        self::assertNull(Ip::parse(''));
        self::assertNull(Ip::parseFour('2001:db8::1'));
        self::assertNull(Ip::parseSix('192.0.2.10'));
    }

    public function testAPrefixWiderThanTheAddressNeverMatches(): void
    {
        $four = Ip::parse('192.0.2.10');

        self::assertNotNull($four);
        self::assertFalse(Ip::matches($four, $four, 33));
        self::assertFalse(Ip::matches($four, $four, -1));
    }
}
