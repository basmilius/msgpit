<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Capture;
use Msgpit\Core\Route;
use Msgpit\Http\Request;
use Msgpit\Http\Response;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    private function route(string $method, string $pattern): Route
    {
        return new Route($method, $pattern, static fn (): Capture => new Capture([], new Response(200)));
    }

    public function testItMatchesAStaticPath(): void
    {
        self::assertSame([], $this->route('POST', '/v2/messages')->match('POST', '/v2/messages'));
    }

    public function testItIsCaseInsensitiveOnTheMethod(): void
    {
        self::assertSame([], $this->route('POST', '/v2/messages')->match('post', '/v2/messages'));
    }

    public function testItRejectsAnotherMethod(): void
    {
        self::assertNull($this->route('POST', '/v2/messages')->match('GET', '/v2/messages'));
    }

    public function testItExtractsPlaceholders(): void
    {
        $params = $this->route('GET', '/v2/messages/{messageId}')->match('GET', '/v2/messages/abc-123');

        self::assertSame(['messageId' => 'abc-123'], $params);
    }

    public function testAPlaceholderDoesNotSpanSlashes(): void
    {
        self::assertNull($this->route('GET', '/v2/messages/{id}')->match('GET', '/v2/messages/a/b'));
    }

    public function testItAnchorsThePattern(): void
    {
        self::assertNull($this->route('GET', '/v2/messages')->match('GET', '/v2/messages/extra'));
        self::assertNull($this->route('GET', '/v2/messages')->match('GET', '/prefix/v2/messages'));
    }
}
