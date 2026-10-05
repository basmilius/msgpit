<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Capture;
use Msgpit\Core\Channel;
use Msgpit\Core\Provider;
use Msgpit\Core\ProviderRegistry;
use Msgpit\Core\Route;
use Msgpit\Http\Response;
use Msgpit\Provider\Spryng\SpryngProvider;
use PHPUnit\Framework\TestCase;

/**
 * The empty state tells you where to point your application, and it reads that from the provider
 * rather than from a sentence someone typed once. That only works if the base url is right.
 */
final class ProviderBaseUrlTest extends TestCase
{
    /** @param list<string> $paths */
    private function providerWith(array $paths, string $id = 'demo'): Provider
    {
        return new class ($paths, $id) implements Provider {
            /** @param list<string> $paths */
            public function __construct(private array $paths, private string $name) {}

            public function id(): string
            {
                return $this->name;
            }

            public function channels(): array
            {
                return [Channel::Sms];
            }

            public function routes(): array
            {
                return array_map(
                    static fn (string $path): Route => new Route(
                        'POST',
                        $path,
                        static fn (): Capture => new Capture([], new Response(200)),
                    ),
                    $this->paths,
                );
            }
        };
    }

    public function testTheSharedDirectoryBecomesTheBaseUrl(): void
    {
        $provider = $this->providerWith(['/v2/messages', '/v2/balance', '/v2/webhooks/events']);

        self::assertSame('/demo/v2', ProviderRegistry::baseUrl($provider));
    }

    public function testItGoesDeeperWhenEveryRouteAgrees(): void
    {
        $provider = $this->providerWith(['/api/v3/sms/send', '/api/v3/sms/status']);

        self::assertSame('/demo/api/v3/sms', ProviderRegistry::baseUrl($provider));
    }

    public function testRoutesWithNothingInCommonLeaveJustThePrefix(): void
    {
        $provider = $this->providerWith(['/send', '/status']);

        self::assertSame('/demo', ProviderRegistry::baseUrl($provider));
    }

    public function testASingleRouteStillGivesItsDirectory(): void
    {
        $provider = $this->providerWith(['/v2/messages']);

        self::assertSame('/demo/v2', ProviderRegistry::baseUrl($provider));
    }

    /** A path with a placeholder describes one endpoint, not where the api lives. */
    public function testPlaceholderRoutesAreIgnored(): void
    {
        $provider = $this->providerWith(['/v2/messages', '/v2/messages/{id}', '/v2/balance']);

        self::assertSame('/demo/v2', ProviderRegistry::baseUrl($provider));
    }

    public function testAProviderWithoutRoutesIsJustItsPrefix(): void
    {
        self::assertSame('/demo', ProviderRegistry::baseUrl($this->providerWith([])));
    }

    /** The one that matters: what an application actually has to configure. */
    public function testSpryngResolvesToItsDocumentedBaseUrl(): void
    {
        self::assertSame('/spryng/v2', ProviderRegistry::baseUrl(new SpryngProvider()));
    }
}
