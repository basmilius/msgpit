<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\SpamReport;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Report;
use Msgpit\Mail\Report\Status;
use Msgpit\Mime\Parser;
use PHPUnit\Framework\TestCase;

/**
 * The deliverability report. Two things matter beyond each check being right: a check that cannot
 * apply must not count against the message, and the evidence has to name what was actually read.
 */
final class MailReportTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function report(array $headers = [], ?string $html = null, ?string $text = 'Hoi.', ?SpamReport $spam = null): Report
    {
        $headers += [
            'From' => 'Afzender <info@example.test>',
            'To' => 'raymond@example.test',
            'Subject' => 'Onderwerp',
            'Date' => 'Mon, 14 Sep 2026 11:00:00 +0200',
            'Message-ID' => '<abc@example.test>',
            'Content-Type' => 'text/plain; charset=utf-8',
        ];

        $lines = [];

        foreach ($headers as $name => $value) {
            if ($value !== '') {
                $lines[] = "{$name}: {$value}";
            }
        }

        $raw = implode("\r\n", $lines) . "\r\n\r\n" . ($text ?? '');

        return Report::build(new Context(Parser::parse($raw), $html, $text, $spam));
    }

    private function finding(Report $report, string $id): \Msgpit\Mail\Report\Finding
    {
        foreach ($report->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        self::fail("No finding with id {$id}");
    }

    public function testAPlainMessageScoresWell(): void
    {
        $report = $this->report();

        self::assertGreaterThanOrEqual(8.0, $report->score());
        self::assertLessThanOrEqual(10.0, $report->score());
    }

    /**
     * The rule that makes the number usable: a mail we caught ourselves has no sending server, so
     * SPF and DKIM are listed as not applicable and left out of the sum. Counting them would mark
     * every test message down for something it cannot have.
     */
    public function testAnInapplicableCheckDoesNotCostPoints(): void
    {
        $report = $this->report();
        $authentication = $this->finding($report, 'authentication');

        self::assertSame(Status::Skip, $authentication->status);
        self::assertSame(0.0, $authentication->penalty);
        self::assertLessThan(count($report->findings), $report->applicable());
    }

    public function testAnImportedMessageIsJudgedOnWhatTheServerRecorded(): void
    {
        $report = $this->report([
            'Authentication-Results' => 'mx.example.test; dkim=pass header.d=example.test; spf=pass; dmarc=pass',
        ]);
        $finding = $this->finding($report, 'authentication');

        self::assertSame(Status::Pass, $finding->status);
        self::assertStringContainsString('DKIM pass', $finding->title);
        self::assertStringContainsString('Authentication-Results:', implode(' ', $finding->evidence));
    }

    public function testAFailingMechanismCostsPoints(): void
    {
        $report = $this->report(['Authentication-Results' => 'mx.example.test; spf=fail; dkim=none']);
        $finding = $this->finding($report, 'authentication');

        self::assertSame(Status::Fail, $finding->status);
        self::assertGreaterThan(0.0, $finding->penalty);
        self::assertLessThan(10.0, $report->score());
    }

    public function testMissingHeadersAreReportedAndCounted(): void
    {
        $report = $this->report(['Date' => '', 'Message-ID' => '']);
        $finding = $this->finding($report, 'required-headers');

        self::assertSame(Status::Fail, $finding->status);
        self::assertStringContainsString('Date', $finding->title);
        self::assertStringContainsString('Message-ID', $finding->title);
    }

    public function testAnHtmlOnlyMessageIsMissingItsTextVersion(): void
    {
        $report = $this->report(html: '<p>Hoi</p>', text: null);

        self::assertSame(Status::Warn, $this->finding($report, 'text-and-html')->status);
    }

    public function testScriptsAndIframesFail(): void
    {
        $report = $this->report(html: '<p>Hoi</p><script>alert(1)</script><iframe src="x"></iframe>', text: 'Hoi');
        $finding = $this->finding($report, 'dangerous-html');

        self::assertSame(Status::Fail, $finding->status);
        self::assertSame(['<script>', '<iframe>'], $finding->evidence);
    }

    public function testImagesWithoutAltAreCounted(): void
    {
        $report = $this->report(html: '<img src="a.png" alt="Logo"><img src="b.png">', text: 'Hoi');
        $finding = $this->finding($report, 'image-alt');

        self::assertSame(Status::Warn, $finding->status);
        self::assertSame(['b.png'], $finding->evidence);
    }

    /** An empty alt is a deliberate "this is decoration", not a forgotten one. */
    public function testAnEmptyAltCountsAsAnswered(): void
    {
        $report = $this->report(html: '<img src="spacer.gif" alt="">', text: 'Hoi');

        self::assertSame(Status::Pass, $this->finding($report, 'image-alt')->status);
    }

    public function testHtmlOverGmailsClippingPointIsFlagged(): void
    {
        $report = $this->report(html: '<p>' . str_repeat('x', 110 * 1024) . '</p>', text: 'Hoi');
        $finding = $this->finding($report, 'gmail-clipping');

        self::assertSame(Status::Warn, $finding->status);
        self::assertStringContainsString('over', $finding->title);
    }

    public function testALinkNamingAnotherDomainThanItOpensIsFlagged(): void
    {
        $report = $this->report(html: '<a href="https://tracker.test/x">https://bank.example</a>', text: 'Hoi');
        $finding = $this->finding($report, 'link-text');

        self::assertSame(Status::Warn, $finding->status);
        self::assertStringContainsString('tracker.test', implode(' ', $finding->evidence));
    }

    /** A subdomain of what the text claims is the same sender, not a mismatch. */
    public function testASubdomainOfTheClaimedHostIsNotAMismatch(): void
    {
        $report = $this->report(html: '<a href="https://mail.example.test/x">example.test</a>', text: 'Hoi');

        self::assertSame(Status::Pass, $this->finding($report, 'link-text')->status);
    }

    public function testAShortenedUrlIsFlagged(): void
    {
        $report = $this->report(html: '<a href="https://bit.ly/abc">Klik</a>', text: 'Hoi');

        self::assertSame(Status::Warn, $this->finding($report, 'url-shorteners')->status);
    }

    public function testOneClickUnsubscribeNeedsBothHeaders(): void
    {
        self::assertSame(Status::Warn, $this->finding($this->report(), 'unsubscribe')->status);
        self::assertSame(Status::Warn, $this->finding(
            $this->report(['List-Unsubscribe' => '<mailto:uit@example.test>']),
            'unsubscribe',
        )->status, 'A mailto alone does not make it one-click');

        $complete = $this->report([
            'List-Unsubscribe' => '<https://example.test/uit>, <mailto:uit@example.test>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);

        self::assertSame(Status::Pass, $this->finding($complete, 'unsubscribe')->status);
    }

    public function testABounceAddressOnAnotherDomainIsFlagged(): void
    {
        $aligned = $this->report(['Return-Path' => '<bounce@example.test>']);
        $crossed = $this->report(['Return-Path' => '<bounce@mailer.test>']);

        self::assertSame(Status::Pass, $this->finding($aligned, 'return-path')->status);
        self::assertSame(Status::Warn, $this->finding($crossed, 'return-path')->status);
    }

    public function testABareSenderAddressIsFlagged(): void
    {
        $report = $this->report(['From' => 'info@example.test']);

        self::assertSame(Status::Warn, $this->finding($report, 'sender-name')->status);
    }

    public function testTheSpamScoreIsSubtractedFromTheTotal(): void
    {
        $clean = $this->report(spam: new SpamReport(false, 0.0, 5.0, [], ''));
        $spammy = $this->report(spam: new SpamReport(true, 7.5, 5.0, [
            ['points' => 7.5, 'name' => 'BAYES_99', 'description' => 'Bayes spam probability is 99 to 100%'],
        ], ''));

        self::assertSame(Status::Pass, $this->finding($clean, 'spam-score')->status);
        self::assertSame(Status::Fail, $this->finding($spammy, 'spam-score')->status);
        self::assertLessThan($clean->score(), $spammy->score());
        self::assertStringContainsString('BAYES_99', implode(' ', $this->finding($spammy, 'spam-score')->evidence));
    }

    /** One spectacular rule should not swallow the whole report. */
    public function testTheSpamPenaltyIsCapped(): void
    {
        $report = $this->report(spam: new SpamReport(true, 40.0, 5.0, [], ''));

        self::assertSame(5.0, $this->finding($report, 'spam-score')->penalty);
    }

    public function testTheScoreNeverGoesBelowZero(): void
    {
        $report = $this->report(
            ['From' => '', 'Date' => '', 'Message-ID' => '', 'Subject' => '', 'Authentication-Results' => 'mx; spf=fail'],
            html: '<script>x</script><iframe></iframe>',
            text: null,
            spam: new SpamReport(true, 40.0, 5.0, [], ''),
        );

        self::assertSame(0.0, $report->score());
    }

    public function testTheReportSurvivesAJsonRoundTrip(): void
    {
        $array = $this->report()->toArray();
        $decoded = json_decode((string) json_encode($array), true);

        self::assertIsArray($decoded);
        // json_encode writes 9.0 as 9, so the UI formats the number rather than trusting its type.
        self::assertEquals($array['score'], $decoded['score']);
        self::assertIsArray($decoded['findings']);
        self::assertCount(count(Report::CHECKS), $decoded['findings']);
    }
}
