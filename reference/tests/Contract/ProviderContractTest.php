<?php

declare(strict_types=1);

namespace Msgpit\Tests\Contract;

use Msgpit\Core\Message;
use Msgpit\Core\Provider;
use Msgpit\Core\ProviderRegistry;
use Msgpit\Core\Router;
use Msgpit\Core\Storage;
use Msgpit\Http\Request;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs for every registered provider and every fixture case, so a new provider is covered the
 * moment it is added to providers.php.
 */
final class ProviderContractTest extends TestCase
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    #[DataProvider('providers')]
    public function testTheIdIsAValidRoutePrefix(Provider $provider): void
    {
        self::assertMatchesRegularExpression('/^[a-z0-9]+$/', $provider->id());
    }

    #[DataProvider('providers')]
    public function testItDeclaresAtLeastOneRouteAndChannel(Provider $provider): void
    {
        self::assertNotEmpty($provider->routes());
        self::assertNotEmpty($provider->channels());
    }

    #[DataProvider('providers')]
    public function testEveryProviderHasDocumentation(Provider $provider): void
    {
        $reflection = new \ReflectionClass($provider);
        $path = dirname((string) $reflection->getFileName()) . '/CLAUDE.md';

        self::assertFileExists($path, "Provider {$provider->id()} must document its API in CLAUDE.md");
    }

    #[DataProvider('cases')]
    public function testFixtureCase(string $providerId, string $case): void
    {
        $directory = self::fixtureDirectory($providerId, $case);
        $fixture = self::readJson($directory . '/request.json');
        $expected = self::readJson($directory . '/expected-response.json');

        $storage = new Storage(new PDO('sqlite::memory:'));
        $registry = ProviderRegistry::fromClasses(self::providerClasses());

        /** @var array<string, string> $headers */
        $headers = is_array($fixture['headers'] ?? null) ? $fixture['headers'] : [];
        $body = array_key_exists('body', $fixture)
            ? json_encode($fixture['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            : '';

        $method = is_string($fixture['method'] ?? null) ? $fixture['method'] : 'GET';
        $path = is_string($fixture['path'] ?? null) ? $fixture['path'] : '/';

        $response = (new Router($registry, $storage))->handle(new Request(
            method: $method,
            path: $path,
            headers: $headers,
            body: $body,
        ));

        self::assertNotNull($response, "No route matched {$method} {$path}");
        self::assertSame($expected['status'], $response->status);

        if ($response->body === '') {
            self::assertSame([], $expected['body'], 'A response without a body cannot match one');
        } else {
            $actual = json_decode($response->body, true);
            self::assertIsArray($actual);
            self::assertMatchesExpectation($expected['body'], $actual, 'response body');
        }

        self::assertStoredMessages($directory, $storage);
    }

    private static function assertStoredMessages(string $directory, Storage $storage): void
    {
        $path = $directory . '/expected-messages.json';

        if (!is_file($path)) {
            self::assertSame([], $storage->all(), 'This case should not store anything');

            return;
        }

        $expected = self::readJson($path);
        // Storage returns newest first; fixtures list recipients in send order.
        $stored = array_map(
            static fn (Message $message): array => $message->toArray(),
            array_reverse($storage->all()),
        );

        self::assertCount(count($expected), $stored);

        foreach ($expected as $index => $fields) {
            self::assertIsArray($fields);

            foreach ($fields as $key => $value) {
                self::assertArrayHasKey($key, $stored[$index]);
                self::assertMatchesExpectation($value, $stored[$index][$key], "message {$index}, field {$key}");
            }
        }
    }

    /** Placeholders like "@uuid" assert a shape, since generated ids differ per run. */
    private static function assertMatchesExpectation(mixed $expected, mixed $actual, string $context): void
    {
        if ($expected === '@uuid') {
            self::assertIsString($actual, $context);
            self::assertMatchesRegularExpression(self::UUID, $actual, $context);

            return;
        }

        if (is_array($expected)) {
            self::assertIsArray($actual, $context);

            foreach ($expected as $key => $value) {
                self::assertArrayHasKey($key, $actual, $context);
                self::assertMatchesExpectation($value, $actual[$key], "{$context}.{$key}");
            }

            return;
        }

        self::assertSame($expected, $actual, $context);
    }

    /** @return iterable<string, array{Provider}> */
    public static function providers(): iterable
    {
        foreach (ProviderRegistry::fromClasses(self::providerClasses())->all() as $provider) {
            yield $provider->id() => [$provider];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        foreach (ProviderRegistry::fromClasses(self::providerClasses())->all() as $provider) {
            $directory = dirname(__DIR__) . '/fixtures/' . $provider->id();

            foreach (glob($directory . '/*/request.json') ?: [] as $file) {
                $case = basename(dirname($file));

                yield "{$provider->id()}/{$case}" => [$provider->id(), $case];
            }
        }
    }

    /** @return list<class-string> */
    private static function providerClasses(): array
    {
        /** @var list<class-string> $classes */
        $classes = require dirname(__DIR__, 2) . '/providers.php';

        return $classes;
    }

    private static function fixtureDirectory(string $providerId, string $case): string
    {
        return dirname(__DIR__) . "/fixtures/{$providerId}/{$case}";
    }

    /** @return array<mixed> */
    private static function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded, "Invalid JSON in {$path}");

        return $decoded;
    }
}
