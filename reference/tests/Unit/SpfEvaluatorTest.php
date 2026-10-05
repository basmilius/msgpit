<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Mail\Spf\Evaluator;
use Msgpit\Mail\Spf\SpfResult;
use Msgpit\Mail\Spf\SpfStatus;
use Msgpit\Mail\Spf\StaticResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpfEvaluatorTest extends TestCase
{
    private const DOMAIN = 'example.test';

    /** The shape almost every record out there has: one include, one literal, one catch all. */
    private const REAL = 'v=spf1 include:_spf.google.com ip4:5.172.45.6 ?all';

    /** @return list<array{string, string, string}> */
    public static function qualifiers(): array
    {
        return [
            ['v=spf1 ip4:192.0.2.0/24 -all', '192.0.2.10', 'pass'],
            ['v=spf1 ip4:192.0.2.0/24 -all', '198.51.100.10', 'fail'],
            ['v=spf1 ip4:192.0.2.0/24 ~all', '198.51.100.10', 'softfail'],
            ['v=spf1 ip4:192.0.2.0/24 ?all', '198.51.100.10', 'neutral'],
            ['v=spf1 ip4:192.0.2.0/24 +all', '198.51.100.10', 'pass'],
            ['v=spf1 -ip4:192.0.2.0/24 +all', '192.0.2.10', 'fail'],
            ['v=spf1 ~ip4:192.0.2.0/24 +all', '192.0.2.10', 'softfail'],
            ['v=spf1 ip4:192.0.2.0/24', '198.51.100.10', 'neutral'],
        ];
    }

    #[DataProvider('qualifiers')]
    public function testTheQualifierOfTheMatchingMechanismDecides(string $record, string $ip, string $expected): void
    {
        $result = $this->evaluate([self::DOMAIN => [$record]], $ip);

        self::assertSame($expected, $result->status->value);
        self::assertSame($record, $result->record);
    }

    public function testTheFirstMatchWins(): void
    {
        $result = $this->evaluate([self::DOMAIN => ['v=spf1 ip4:192.0.2.10 -ip4:192.0.2.0/24 -all']], '192.0.2.10');

        self::assertSame(SpfStatus::Pass, $result->status);
        self::assertSame('ip4:192.0.2.10', $result->mechanism);
    }

    /** @return list<array{string, string, bool}> */
    public static function ranges(): array
    {
        return [
            ['ip4:192.0.2.0/24', '192.0.2.255', true],
            ['ip4:192.0.2.0/24', '192.0.3.0', false],
            ['ip4:192.0.2.0/24', '192.0.1.255', false],
            ['ip4:5.172.45.6', '5.172.45.6', true],
            ['ip4:5.172.45.6', '5.172.45.7', false],
            ['ip4:192.0.2.0/24', '::ffff:192.0.2.10', true],
            ['ip4:192.0.2.0/24', '2001:db8::1', false],
            ['ip6:2001:db8::/32', '2001:db8:1234::1', true],
            ['ip6:2001:db8::/32', '2001:db9::1', false],
            ['ip6:2001:db8::1', '2001:db8::1', true],
            ['ip6:2001:db8::/32', '192.0.2.10', false],
        ];
    }

    #[DataProvider('ranges')]
    public function testItMatchesAddressRangesOnBits(string $mechanism, string $ip, bool $matches): void
    {
        $result = $this->evaluate([self::DOMAIN => ['v=spf1 ' . $mechanism . ' -all']], $ip);

        self::assertSame($matches ? SpfStatus::Pass : SpfStatus::Fail, $result->status);
    }

    public function testAMatchesTheAddressesOfTheDomainItself(): void
    {
        $resolver = new StaticResolver(
            txt: [self::DOMAIN => ['v=spf1 a -all']],
            addresses: [self::DOMAIN => ['192.0.2.10', '2001:db8::1']],
        );

        self::assertSame(SpfStatus::Pass, (new Evaluator($resolver))->evaluate('2001:db8::1', self::DOMAIN)->status);
        self::assertSame(SpfStatus::Fail, (new Evaluator($resolver))->evaluate('192.0.2.11', self::DOMAIN)->status);
    }

    public function testATakesADomainAndAPrefix(): void
    {
        $resolver = new StaticResolver(
            txt: [self::DOMAIN => ['v=spf1 a:mail.example.test/24 -all']],
            addresses: ['mail.example.test' => ['192.0.2.10']],
        );

        self::assertSame(SpfStatus::Pass, (new Evaluator($resolver))->evaluate('192.0.2.200', self::DOMAIN)->status);
        self::assertSame(SpfStatus::Fail, (new Evaluator($resolver))->evaluate('192.0.3.200', self::DOMAIN)->status);
    }

    public function testADualPrefixAppliesPerFamily(): void
    {
        $resolver = new StaticResolver(
            txt: [self::DOMAIN => ['v=spf1 a/24//48 -all']],
            addresses: [self::DOMAIN => ['192.0.2.10', '2001:db8:1::1']],
        );

        self::assertSame(SpfStatus::Pass, (new Evaluator($resolver))->evaluate('192.0.2.99', self::DOMAIN)->status);
        self::assertSame(SpfStatus::Pass, (new Evaluator($resolver))->evaluate('2001:db8:1::ffff', self::DOMAIN)->status);
        self::assertSame(SpfStatus::Fail, (new Evaluator($resolver))->evaluate('2001:db8:2::1', self::DOMAIN)->status);
    }

    public function testMxResolvesTheExchangersBehindTheName(): void
    {
        $resolver = new StaticResolver(
            txt: [self::DOMAIN => ['v=spf1 mx -all']],
            addresses: ['mx1.example.test' => ['192.0.2.10'], 'mx2.example.test' => ['192.0.2.20']],
            mx: [self::DOMAIN => ['mx1.example.test', 'mx2.example.test']],
        );

        self::assertSame(SpfStatus::Pass, (new Evaluator($resolver))->evaluate('192.0.2.20', self::DOMAIN)->status);
        self::assertSame(SpfStatus::Fail, (new Evaluator($resolver))->evaluate('192.0.2.30', self::DOMAIN)->status);
    }

    public function testMxCountsAsOneLookupAndRefusesMoreThanTenExchangers(): void
    {
        $hosts = [];
        $addresses = [];

        for ($index = 1; $index <= 11; $index++) {
            $hosts[] = sprintf('mx%d.example.test', $index);
            $addresses[sprintf('mx%d.example.test', $index)] = ['192.0.2.' . $index];
        }

        $resolver = new StaticResolver(
            txt: [self::DOMAIN => ['v=spf1 mx -all']],
            addresses: $addresses,
            mx: [self::DOMAIN => $hosts],
        );

        $result = (new Evaluator($resolver))->evaluate('192.0.2.1', self::DOMAIN);

        self::assertSame(SpfStatus::PermError, $result->status);
        self::assertStringContainsString('more than 10 mail exchangers', $result->reason);
    }

    public function testExistsMatchesWhenTheNameResolves(): void
    {
        $resolver = new StaticResolver(
            txt: [self::DOMAIN => ['v=spf1 exists:known.example.test -all']],
            addresses: ['known.example.test' => ['127.0.0.2']],
        );

        self::assertSame(SpfStatus::Pass, (new Evaluator($resolver))->evaluate('198.51.100.1', self::DOMAIN)->status);
    }

    public function testAnIncludeThatPassesMatches(): void
    {
        $result = $this->evaluate([
            self::DOMAIN => ['v=spf1 include:sender.example.test -all'],
            'sender.example.test' => ['v=spf1 ip4:192.0.2.0/24 -all'],
        ], '192.0.2.10');

        self::assertSame(SpfStatus::Pass, $result->status);
        self::assertSame('include:sender.example.test', $result->mechanism);
        self::assertSame(1, $result->lookups);
    }

    /** An include that does not pass is no match, so the fail inside it never becomes the verdict. */
    public function testAnIncludeThatFailsIsSimplyNoMatch(): void
    {
        $result = $this->evaluate([
            self::DOMAIN => ['v=spf1 include:sender.example.test ip4:198.51.100.0/24 -all'],
            'sender.example.test' => ['v=spf1 ip4:192.0.2.0/24 -all'],
        ], '198.51.100.10');

        self::assertSame(SpfStatus::Pass, $result->status);
        self::assertSame('ip4:198.51.100.0/24', $result->mechanism);
    }

    public function testTheQualifierOfTheIncludeItselfApplies(): void
    {
        $result = $this->evaluate([
            self::DOMAIN => ['v=spf1 -include:sender.example.test +all'],
            'sender.example.test' => ['v=spf1 ip4:192.0.2.0/24 -all'],
        ], '192.0.2.10');

        self::assertSame(SpfStatus::Fail, $result->status);
    }

    public function testAnIncludeOfADomainWithoutARecordIsAPermerror(): void
    {
        $result = $this->evaluate([self::DOMAIN => ['v=spf1 include:nothing.example.test -all']], '192.0.2.10');

        self::assertSame(SpfStatus::PermError, $result->status);
        self::assertStringContainsString('without an SPF record', $result->reason);
    }

    public function testAPermerrorInsideAnIncludeTravelsOutwards(): void
    {
        $result = $this->evaluate([
            self::DOMAIN => ['v=spf1 include:sender.example.test +all'],
            'sender.example.test' => ['v=spf1 ip4:not-an-address -all'],
        ], '192.0.2.10');

        self::assertSame(SpfStatus::PermError, $result->status);
        self::assertStringContainsString('valid IPv4 address', $result->reason);
    }

    public function testRedirectReplacesTheEvaluation(): void
    {
        $result = $this->evaluate([
            self::DOMAIN => ['v=spf1 redirect=policy.example.test'],
            'policy.example.test' => ['v=spf1 ip4:192.0.2.0/24 -all'],
        ], '198.51.100.10');

        self::assertSame(SpfStatus::Fail, $result->status);
        self::assertSame('v=spf1 ip4:192.0.2.0/24 -all', $result->record);
        self::assertStringContainsString('redirects to policy.example.test', $result->reason);
        self::assertSame(1, $result->lookups);
    }

    /** all ends the evaluation, so a redirect beside it is never reached (RFC 7208 6.1). */
    public function testAllWinsFromRedirect(): void
    {
        $result = $this->evaluate([
            self::DOMAIN => ['v=spf1 ~all redirect=policy.example.test'],
            'policy.example.test' => ['v=spf1 +all'],
        ], '198.51.100.10');

        self::assertSame(SpfStatus::SoftFail, $result->status);
        self::assertSame(0, $result->lookups);
    }

    public function testARedirectToADomainWithoutARecordIsAPermerror(): void
    {
        $result = $this->evaluate([self::DOMAIN => ['v=spf1 redirect=nothing.example.test']], '192.0.2.10');

        self::assertSame(SpfStatus::PermError, $result->status);
        self::assertStringContainsString('publishes no SPF record', $result->reason);
    }

    public function testTenLookupsAreAllowedAndTheEleventhIsNot(): void
    {
        self::assertSame(SpfStatus::Neutral, $this->chained(10)->status);

        $eleventh = $this->chained(11);

        self::assertSame(SpfStatus::PermError, $eleventh->status);
        self::assertStringContainsString('more than 10 DNS lookups', $eleventh->reason);
    }

    public function testTwoVoidLookupsAreAllowedAndTheThirdIsNot(): void
    {
        $allowed = $this->evaluate([self::DOMAIN => ['v=spf1 a:one.example.test a:two.example.test ?all']], '192.0.2.10');

        self::assertSame(SpfStatus::Neutral, $allowed->status);

        $refused = $this->evaluate([self::DOMAIN => ['v=spf1 a:one.example.test a:two.example.test a:three.example.test ?all']], '192.0.2.10');

        self::assertSame(SpfStatus::PermError, $refused->status);
        self::assertStringContainsString('more than 2 void DNS lookups', $refused->reason);
    }

    public function testTwoSpfRecordsArePermerror(): void
    {
        $result = $this->evaluate([self::DOMAIN => ['v=spf1 ip4:192.0.2.0/24 -all', 'v=spf1 -all']], '192.0.2.10');

        self::assertSame(SpfStatus::PermError, $result->status);
        self::assertStringContainsString('publishes 2 SPF records', $result->reason);
    }

    /** @return list<array{list<string>}> */
    public static function withoutARecord(): array
    {
        return [
            [[]],
            [['']],
            [['google-site-verification=abc']],
            [['v=spf10 -all']],
        ];
    }

    /** @param list<string> $records */
    #[DataProvider('withoutARecord')]
    public function testADomainWithoutAnSpfRecordIsNone(array $records): void
    {
        $result = $this->evaluate([self::DOMAIN => $records], '192.0.2.10');

        self::assertSame(SpfStatus::None, $result->status);
        self::assertNull($result->record);
    }

    public function testARecordWithAMacroIsReportedAsUnsupported(): void
    {
        $result = $this->evaluate([self::DOMAIN => ['v=spf1 exists:%{i}._spf.example.test -all']], '192.0.2.10');

        self::assertSame(SpfStatus::PermError, $result->status);
        self::assertStringContainsString('macro', $result->reason);
        self::assertSame('v=spf1 exists:%{i}._spf.example.test -all', $result->record);
    }

    public function testPtrIsNotedAndNotEvaluated(): void
    {
        $result = $this->evaluate([self::DOMAIN => ['v=spf1 ptr ptr:example.test -all']], '192.0.2.10');

        self::assertSame(SpfStatus::Fail, $result->status);
        self::assertSame('-all', $result->mechanism);
        self::assertCount(1, $result->notes);
        self::assertStringContainsString('ptr', $result->notes[0]);
        self::assertSame(0, $result->lookups);
    }

    public function testAFailingResolverGivesTemperror(): void
    {
        $resolver = new StaticResolver(
            txt: [self::DOMAIN => ['v=spf1 include:sender.example.test -all']],
            failures: ['sender.example.test'],
        );

        $result = (new Evaluator($resolver))->evaluate('192.0.2.10', self::DOMAIN);

        self::assertSame(SpfStatus::TempError, $result->status);
        self::assertStringContainsString('sender.example.test', $result->reason);
    }

    /** @return list<array{string, string}> */
    public static function malformed(): array
    {
        return [
            ['v=spf1 ip4:192.0.2.0/33 -all', 'prefix length'],
            ['v=spf1 ip4:2001:db8::/32 -all', 'valid IPv4 address'],
            ['v=spf1 ip6:192.0.2.1 -all', 'valid IPv6 address'],
            ['v=spf1 ip4 -all', 'needs an address'],
            ['v=spf1 include: -all', 'not a valid name'],
            ['v=spf1 wat:example.test -all', 'does not exist in SPF'],
            ['v=spf1 all:example.test -all', 'takes no value'],
            ['v=spf1 redirect=a.example.test redirect=b.example.test', 'more than one redirect'],
        ];
    }

    #[DataProvider('malformed')]
    public function testAMalformedRecordIsAPermerror(string $record, string $reason): void
    {
        $result = $this->evaluate([self::DOMAIN => [$record]], '192.0.2.10');

        self::assertSame(SpfStatus::PermError, $result->status);
        self::assertStringContainsString($reason, $result->reason);
    }

    public function testUnknownModifiersAreIgnored(): void
    {
        $result = $this->evaluate([self::DOMAIN => ['v=spf1 ip4:192.0.2.0/24 exp=why.example.test moo=cow -all']], '192.0.2.10');

        self::assertSame(SpfStatus::Pass, $result->status);
    }

    /** @return list<array{string, string, int}> */
    public static function realRecord(): array
    {
        return [
            ['5.172.45.6', 'pass', 4],
            ['35.190.247.10', 'pass', 2],
            ['2001:4860:4000::1', 'pass', 3],
            ['198.51.100.10', 'neutral', 4],
        ];
    }

    #[DataProvider('realRecord')]
    public function testItEvaluatesTheRecordShapeThatIsEverywhere(string $ip, string $expected, int $lookups): void
    {
        $result = $this->evaluate([
            self::DOMAIN => [self::REAL],
            '_spf.google.com' => ['v=spf1 include:_netblocks.google.com include:_netblocks2.google.com include:_netblocks3.google.com ~all'],
            '_netblocks.google.com' => ['v=spf1 ip4:35.190.247.0/24 ip4:64.233.160.0/19 ~all'],
            '_netblocks2.google.com' => ['v=spf1 ip6:2001:4860:4000::/36 ~all'],
            '_netblocks3.google.com' => ['v=spf1 ip4:172.217.0.0/19 ~all'],
        ], $ip);

        self::assertSame($expected, $result->status->value);
        self::assertSame($lookups, $result->lookups);
    }

    public function testAnAddressThatIsNotAnAddressIsAPermerror(): void
    {
        $result = $this->evaluate([self::DOMAIN => ['v=spf1 +all']], 'not-an-ip');

        self::assertSame(SpfStatus::PermError, $result->status);
        self::assertStringContainsString('not an IP address', $result->reason);
    }

    public function testTheResultCarriesEverythingTheUiNeeds(): void
    {
        $result = $this->evaluate([self::DOMAIN => ['v=spf1 ip4:192.0.2.0/24 -all']], '192.0.2.10');

        self::assertSame([
            'status' => 'pass',
            'reason' => '192.0.2.10 matches "ip4:192.0.2.0/24" in the record of example.test.',
            'record' => 'v=spf1 ip4:192.0.2.0/24 -all',
            'mechanism' => 'ip4:192.0.2.0/24',
            'lookups' => 0,
            'notes' => [],
        ], $result->toArray());
    }

    /** A record of $count includes, each of which answers no, so the count is the budget spent. */
    private function chained(int $count): SpfResult
    {
        $terms = [];
        $records = [];

        for ($index = 1; $index <= $count; $index++) {
            $name = sprintf('hop%d.example.test', $index);
            $terms[] = 'include:' . $name;
            $records[$name] = ['v=spf1 -all'];
        }

        $records[self::DOMAIN] = ['v=spf1 ' . implode(' ', $terms) . ' ?all'];

        return (new Evaluator(new StaticResolver(txt: $records)))->evaluate('192.0.2.10', self::DOMAIN);
    }

    /** @param array<string, list<string>> $txt */
    private function evaluate(array $txt, string $ip): SpfResult
    {
        return (new Evaluator(new StaticResolver(txt: $txt)))->evaluate($ip, self::DOMAIN);
    }
}
