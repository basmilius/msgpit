<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Capture;
use Msgpit\Core\Channel;
use Msgpit\Core\Message;
use Msgpit\Core\Provider;
use Msgpit\Core\ProviderRegistry;
use Msgpit\Core\Route;
use Msgpit\Core\Router;
use Msgpit\Core\Scenario;
use Msgpit\Core\Storage;
use Msgpit\Core\SupportsErrorScenarios;
use Msgpit\Http\Request;
use Msgpit\Http\Response;
use PDO;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Storage $storage;

    protected function setUp(): void
    {
        $this->storage = new Storage(new PDO('sqlite::memory:'));
    }

    private function router(Provider ...$providers): Router
    {
        return new Router(new ProviderRegistry(array_values($providers)), $this->storage);
    }

    private function send(Router $router, string $to = '+31612345678', string $path = '/demo/send'): ?Response
    {
        return $router->handle(new Request('POST', $path, [], [], json_encode(['to' => $to], JSON_THROW_ON_ERROR)));
    }

    public function testItRoutesOnTheProviderPrefix(): void
    {
        $response = $this->send($this->router(new DemoProvider()));

        self::assertNotNull($response);
        self::assertSame(202, $response->status);
        self::assertCount(1, $this->storage->all());
    }

    public function testAnUnknownPrefixIsNotOurs(): void
    {
        self::assertNull($this->send($this->router(new DemoProvider()), path: '/other/send'));
    }

    public function testAnUnknownPathWithinAProviderIsNotOurs(): void
    {
        self::assertNull($this->send($this->router(new DemoProvider()), path: '/demo/nope'));
    }

    public function testItStoresTheMaskedRawRequest(): void
    {
        $router = $this->router(new DemoProvider());
        $router->handle(new Request('POST', '/demo/send', ['x-api-key' => 'secret-key-value'], [], '{"to":"+31612345678"}'));

        $stored = $this->storage->all();
        $raw = (string) $this->storage->rawRequest($stored[0]->id);

        self::assertStringContainsString('secr********alue', $raw);
        self::assertStringNotContainsString('secret-key-value', $raw);
    }

    public function testAMagicRecipientTriggersTheProviderErrorResponse(): void
    {
        $response = $this->send($this->router(new DemoProvider()), '+31600000003');

        self::assertNotNull($response);
        self::assertSame(429, $response->status);
    }

    public function testAScenarioStoresNothing(): void
    {
        $this->send($this->router(new DemoProvider()), '+31600000001');

        self::assertSame([], $this->storage->all(), 'A failed request never happened');
    }

    public function testTheOneShotScenarioAppliesOnceAndThenClears(): void
    {
        $router = $this->router(new DemoProvider());
        $this->storage->setScenario(Scenario::ServerError);

        $first = $this->send($router);
        $second = $this->send($router);

        self::assertSame(500, $first?->status);
        self::assertSame(202, $second?->status);
        self::assertCount(1, $this->storage->all(), 'Only the second request is stored');
    }

    public function testTheOneShotScenarioSurvivesAProviderThatIgnoresIt(): void
    {
        $router = $this->router(new PlainProvider(), new DemoProvider());
        $this->storage->setScenario(Scenario::ServerError);

        $ignored = $this->send($router, path: '/plain/send');
        $applied = $this->send($router);

        self::assertSame(202, $ignored?->status, 'A provider without scenario support answers normally');
        self::assertSame(500, $applied?->status, 'The toggle is still armed for a provider that does support it');
    }
}

/** Minimal provider so the router is tested without leaning on a real one. */
final class DemoProvider implements Provider, SupportsErrorScenarios
{
    public function id(): string
    {
        return 'demo';
    }

    public function channels(): array
    {
        return [Channel::Sms];
    }

    public function routes(): array
    {
        return [new Route('POST', '/send', $this->send(...))];
    }

    /** @param array<string, string> $params */
    private function send(Request $request, array $params): Capture
    {
        $to = $request->json()['to'] ?? '';

        return new Capture(
            [Message::create('batch', $this->id(), Channel::Sms, is_string($to) ? $to : '', 'Hello')],
            Response::json(['ok' => true], 202),
        );
    }

    public function errorResponse(Scenario $scenario): Response
    {
        return Response::json(['error' => $scenario->value], match ($scenario) {
            Scenario::InvalidNumber => 400,
            Scenario::Unauthorized => 401,
            Scenario::RateLimited => 429,
            Scenario::ServerError => 500,
        });
    }
}

/** A provider without SupportsErrorScenarios, to prove the toggle is left alone. */
final class PlainProvider implements Provider
{
    public function id(): string
    {
        return 'plain';
    }

    public function channels(): array
    {
        return [Channel::Sms];
    }

    public function routes(): array
    {
        return [new Route('POST', '/send', static fn (): Capture => new Capture([], Response::json(['ok' => true], 202)))];
    }
}
