<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Mime\HtmlCheck;
use Msgpit\Mime\HtmlCheckResult;
use PHPUnit\Framework\TestCase;

/**
 * Against a small invented dataset, so the expectations do not move when caniemail publishes new
 * measurements. One test reads the bundled data, to prove it is still shaped the way we think.
 */
final class HtmlCheckTest extends TestCase
{
    private const SAMPLE = __DIR__ . '/../fixtures/caniemail/sample.json';

    protected function setUp(): void
    {
        HtmlCheck::forget();
    }

    protected function tearDown(): void
    {
        HtmlCheck::forget();
    }

    private function analyse(string $html): ?HtmlCheckResult
    {
        return HtmlCheck::analyse($html, self::SAMPLE);
    }

    public function testItFindsElementsAttributesAndProperties(): void
    {
        $result = $this->analyse('<table align="center"><tr><td style="margin:0;color:red">Hoi</td></tr></table>');

        self::assertNotNull($result);

        $slugs = array_map(static fn ($f): string => $f->slug, $result->findings);

        self::assertContains('html-table', $slugs);
        self::assertContains('html-align', $slugs);
        self::assertContains('css-margin', $slugs);
        self::assertContains('css-color', $slugs);
    }

    public function testItReadsPropertiesFromAStyleElementToo(): void
    {
        $result = $this->analyse('<html><head><style>.a { gap: 8px; }</style></head><body>Hoi</body></html>');

        self::assertNotNull($result);

        $slugs = array_map(static fn ($f): string => $f->slug, $result->findings);

        self::assertContains('css-gap', $slugs);
        self::assertContains('html-style', $slugs);
    }

    public function testCommentsInCssAreNotProperties(): void
    {
        $result = $this->analyse('<style>/* color: red; */ .a { margin: 0 }</style>');

        self::assertNotNull($result);

        $slugs = array_map(static fn ($f): string => $f->slug, $result->findings);

        self::assertContains('css-margin', $slugs);
        self::assertNotContains('css-color', $slugs, 'A commented out declaration is not used');
    }

    public function testRepeatedUseIsCountedButScoredOnce(): void
    {
        $result = $this->analyse('<p style="margin:0">a</p><p style="margin:4px">b</p><p style="margin:8px">c</p>');

        self::assertNotNull($result);

        $margin = $result->findings[0];

        self::assertSame('css-margin', $margin->slug);
        self::assertSame(3, $margin->occurrences);
        self::assertSame(4, $margin->tested(), 'Still one feature, tested against four clients');
    }

    public function testThePercentagesAddUp(): void
    {
        $result = $this->analyse('<p style="color:red">Hoi</p>');

        self::assertNotNull($result);
        self::assertEqualsWithDelta(
            100.0,
            $result->percentage('supported') + $result->percentage('partial') + $result->percentage('unsupported'),
            0.01,
        );
    }

    public function testSomethingEveryClientSupportsScoresFull(): void
    {
        $result = $this->analyse('<p style="color:red">Hoi</p>');

        self::assertNotNull($result);
        self::assertSame(100.0, $result->percentage('supported'));
        self::assertSame([], $result->warnings(), 'Nothing to warn about');
    }

    public function testTheWorstOffenderComesFirst(): void
    {
        $result = $this->analyse('<div style="color:red;margin:0;gap:8px">Hoi</div>');

        self::assertNotNull($result);
        self::assertSame('css-gap', $result->findings[0]->slug, 'gap is the least supported of the three');
    }

    /** Unknown is not evidence either way, so a feature nobody tested says nothing. */
    public function testAFeatureWithOnlyUnknownsIsLeftOut(): void
    {
        $result = $this->analyse('<p style="filter:blur(2px);color:red">Hoi</p>');

        self::assertNotNull($result);

        $slugs = array_map(static fn ($f): string => $f->slug, $result->findings);

        self::assertNotContains('css-filter', $slugs);
    }

    public function testAnUnknownVerdictDoesNotCountTowardsAFeature(): void
    {
        $result = $this->analyse('<table align="center"><tr><td>Hoi</td></tr></table>');

        self::assertNotNull($result);

        $align = array_values(array_filter($result->findings, static fn ($f): bool => $f->slug === 'html-align'))[0];

        self::assertSame(3, $align->tested(), 'Three verdicts, the fourth is unknown');
    }

    public function testHtmlWeKnowNothingAboutYieldsNothing(): void
    {
        self::assertNull($this->analyse('<blink>Hoi</blink>'));
        self::assertNull($this->analyse(''));
    }

    public function testMalformedHtmlIsStillAnalysed(): void
    {
        $result = $this->analyse('<div style="color:red"><p>niet gesloten<table><tr><td>rommel');

        self::assertNotNull($result, 'Mail html is rarely well formed');
    }

    public function testWithoutDataThereIsNoVerdict(): void
    {
        self::assertNull(HtmlCheck::analyse('<p style="color:red">Hoi</p>', '/does/not/exist.json'));
    }

    /** The bundled data has to keep the shape the check expects. */
    public function testTheBundledDataStillWorks(): void
    {
        $result = HtmlCheck::analyse('<p style="margin:0;color:red">Hoi</p>');

        self::assertNotNull($result, 'data/caniemail.json should be present and readable');
        self::assertGreaterThan(0, $result->tested());
        self::assertNotNull($result->dataUpdated);

        $array = $result->toArray();

        self::assertArrayHasKey('supported', $array);
        self::assertArrayHasKey('warnings', $array);
    }
}
