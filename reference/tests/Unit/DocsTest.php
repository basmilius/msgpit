<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Docs;
use PHPUnit\Framework\TestCase;

final class DocsTest extends TestCase
{
    private Docs $docs;

    protected function setUp(): void
    {
        $this->docs = new Docs(dirname(__DIR__, 2) . '/docs');
    }

    public function testItListsThePagesInReadingOrder(): void
    {
        $pages = $this->docs->index();

        self::assertNotEmpty($pages);

        $slugs = array_column($pages, 'slug');
        $sorted = $slugs;
        sort($sorted);

        self::assertSame($sorted, $slugs, 'Pages are ordered by their numeric filename prefix');
    }

    public function testEveryPageHasATitleTakenFromItsHeading(): void
    {
        foreach ($this->docs->index() as $page) {
            self::assertNotSame('', $page['title']);
            self::assertStringStartsWith('# ' . $page['title'], (string) $this->docs->page($page['slug']));
        }
    }

    public function testItReadsAPage(): void
    {
        self::assertStringContainsString('Magic recipient numbers', (string) $this->docs->page('03-scenarios'));
    }

    public function testAnUnknownPageIsNull(): void
    {
        self::assertNull($this->docs->page('does-not-exist'));
    }

    public function testItRefusesToEscapeTheDocsDirectory(): void
    {
        self::assertNull($this->docs->page('../composer'));
        self::assertNull($this->docs->page('../../etc/passwd'));
        self::assertNull($this->docs->page('foo/bar'));
    }

    public function testTheScenarioDocumentationDefersToTheApplication(): void
    {
        // The magic numbers must not be typed out by hand, or they will drift from the code.
        self::assertStringContainsString('<!-- scenarios -->', (string) $this->docs->page('03-scenarios'));
    }
}
