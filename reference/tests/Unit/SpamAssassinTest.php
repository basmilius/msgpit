<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\SpamAssassin;
use Msgpit\Core\SpamReport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The parsing is tested against replies a real spamd gave us, kept as fixtures. The socket side
 * is exercised end to end in CI, where a spamd actually runs.
 */
final class SpamAssassinTest extends TestCase
{
    private function parse(string $fixture): ?SpamReport
    {
        $path = dirname(__DIR__) . '/fixtures/spamassassin/' . $fixture;

        self::assertFileExists($path);

        $method = new ReflectionMethod(SpamAssassin::class, 'parse');

        $report = $method->invoke(null, (string) file_get_contents($path));

        self::assertTrue($report === null || $report instanceof SpamReport);

        return $report;
    }

    public function testItReadsTheVerdictOfASpammyMessage(): void
    {
        $report = $this->parse('report.txt');

        self::assertNotNull($report);
        self::assertTrue($report->spam);
        self::assertSame(6.2, $report->score);
        self::assertSame(5.0, $report->threshold);
    }

    public function testItReadsTheVerdictOfACleanMessage(): void
    {
        $report = $this->parse('clean-report.txt');

        self::assertNotNull($report);
        self::assertFalse($report->spam);
        self::assertLessThan($report->threshold, $report->score);
    }

    /** The score alone says something is wrong; the rules say what. */
    public function testItReadsTheRulesThatFired(): void
    {
        $report = $this->parse('report.txt');

        self::assertNotNull($report);
        self::assertNotEmpty($report->rules);

        foreach ($report->rules as $rule) {
            self::assertNotSame('', $rule['name']);
            self::assertNotSame('', $rule['description']);
        }

        $names = array_column($report->rules, 'name');

        self::assertContains('MISSING_DATE', $names);
    }

    public function testTheRulePointsAddUpToTheScore(): void
    {
        $report = $this->parse('report.txt');

        self::assertNotNull($report);
        self::assertEqualsWithDelta(
            $report->score,
            array_sum(array_column($report->rules, 'points')),
            0.15,
            'The reported score should match the rules it lists',
        );
    }

    /** A description wrapped over several lines belongs to the rule above it, not to a new one. */
    public function testAWrappedDescriptionStaysWithItsRule(): void
    {
        $method = new ReflectionMethod(SpamAssassin::class, 'rules');

        $rules = $method->invoke(null, <<<'REPORT'
             pts rule name              description
            ---- ---------------------- --------------------------------------------------
             1.4 MISSING_DATE           Missing Date: header
             0.0 URIBL_BLOCKED          ADMINISTRATOR NOTICE: The query to URIBL was
                                        blocked. See http://wiki.apache.org/spamassassin
                                        for more information. [URI: solvidi.nl]
             2.7 RISK_FREE              No risk!
            REPORT);

        self::assertIsArray($rules);
        self::assertCount(3, $rules, 'The wrapped lines must not become rules of their own');

        $names = [];
        $descriptions = [];

        foreach ($rules as $rule) {
            self::assertIsArray($rule);
            $names[] = $rule['name'];
            $descriptions[] = $rule['description'];
        }

        self::assertIsString($descriptions[1]);
        self::assertStringContainsString('for more information', $descriptions[1]);
        self::assertSame('RISK_FREE', $names[2]);
    }

    public function testAnUnparseableReplyYieldsNothing(): void
    {
        $method = new ReflectionMethod(SpamAssassin::class, 'parse');

        self::assertNull($method->invoke(null, 'SPAMD/1.1 76 Bad header line'));
        self::assertNull($method->invoke(null, ''));
    }

    /** Scoring is a nicety; a daemon that is down must never cost us the message. */
    public function testAnUnreachableDaemonReturnsNothingRatherThanThrowing(): void
    {
        // Port 1 on localhost: nothing listens there, and it fails fast.
        $report = (new SpamAssassin('127.0.0.1:1', timeout: 1))->check("Subject: Test\r\n\r\nHallo");

        self::assertNull($report);
    }

    public function testItIsOnlyConfiguredWhenTheEnvironmentSaysSo(): void
    {
        putenv('MSGPIT_SPAMASSASSIN');
        self::assertNull(SpamAssassin::fromEnvironment());

        putenv('MSGPIT_SPAMASSASSIN=spamassassin:783');
        self::assertNotNull(SpamAssassin::fromEnvironment());

        putenv('MSGPIT_SPAMASSASSIN');
    }

    public function testAReportSurvivesBeingStoredAsJson(): void
    {
        $report = $this->parse('report.txt');

        self::assertNotNull($report);

        $decoded = json_decode((string) json_encode($report->toArray()), true);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = SpamReport::fromArray($decoded);

        self::assertSame($report->spam, $restored->spam);
        self::assertSame($report->score, $restored->score);
        self::assertCount(count($report->rules), $restored->rules);
    }

    public function testARestoredReportToleratesMissingFields(): void
    {
        $restored = SpamReport::fromArray([]);

        self::assertFalse($restored->spam);
        self::assertSame(0.0, $restored->score);
        self::assertSame(5.0, $restored->threshold);
        self::assertSame([], $restored->rules);
    }
}
