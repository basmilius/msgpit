<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Capture;
use Msgpit\Core\Route;
use Msgpit\Http\Request;
use Msgpit\Provider\Spryng\SpryngProvider;
use PHPUnit\Framework\TestCase;

final class SpryngWebhookTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MSGPIT_SPRYNG_DLR_URL');
        putenv('MSGPIT_SPRYNG_DLR_HEADER');
        putenv('MSGPIT_SPRYNG_DLR_SECRET');
    }

    private function call(string $method, string $path): Capture
    {
        $request = new Request($method, $path, ['x-api-key' => 'test-key-abcdef']);

        foreach ((new SpryngProvider())->routes() as $route) {
            $params = $route->match($method, $path);

            if ($params !== null) {
                return ($route->handler)($request, $params);
            }
        }

        self::fail("No route for {$method} {$path}");
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path): array
    {
        $decoded = json_decode($this->call($method, $path)->response->body, true);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<string> */
    private function eventIds(): array
    {
        $data = $this->json('GET', '/v2/webhooks/events')['data'];

        self::assertIsArray($data);

        $ids = [];

        foreach ($data as $event) {
            self::assertIsArray($event);
            self::assertIsString($event['id']);
            $ids[] = $event['id'];
        }

        return $ids;
    }

    /**
     * The documentation calls these sms-message-delivered and friends. The live endpoint does not
     * use that prefix, and a client that matches its own subscriptions against this list then sees
     * nothing as subscribed.
     */
    public function testEventNamesHaveNoSmsPrefix(): void
    {
        foreach ($this->eventIds() as $id) {
            self::assertStringStartsNotWith('sms-', $id, 'The live endpoint has no sms- prefix');
        }
    }

    public function testItListsEverySixEventsTheApiReturns(): void
    {
        self::assertSame([
            'message-delivered',
            'message-failed',
            'message-received',
            'inbound-opted-out',
            'message-updated',
            'schedule-updated',
        ], $this->eventIds());
    }

    public function testEveryEventHasANameAndDescription(): void
    {
        $data = $this->json('GET', '/v2/webhooks/events')['data'];

        self::assertIsArray($data);

        foreach ($data as $event) {
            self::assertIsArray($event);
            self::assertNotSame('', $event['name'] ?? '');
            self::assertNotSame('', $event['description'] ?? '');
        }
    }

    /** A subscription naming an event the catalogue does not list can never show as subscribed. */
    public function testSubscriptionsUseNamesFromTheEventCatalogue(): void
    {
        putenv('MSGPIT_SPRYNG_DLR_URL=http://web/sms-status.php');

        $data = $this->json('GET', '/v2/webhooks/subscriptions')['data'];

        self::assertIsArray($data);
        self::assertIsArray($data['events']);
        self::assertNotSame([], $data['events'], 'A configured callback URL should show up');

        $known = $this->eventIds();

        foreach ($data['events'] as $subscription) {
            self::assertIsArray($subscription);
            self::assertContains($subscription['eventType'], $known);
            self::assertSame([['url' => 'http://web/sms-status.php']], $subscription['callbacks']);
        }
    }

    public function testWithoutACallbackUrlThereAreNoSubscriptions(): void
    {
        putenv('MSGPIT_SPRYNG_DLR_URL');

        $data = $this->json('GET', '/v2/webhooks/subscriptions')['data'];

        self::assertIsArray($data);
        self::assertSame([], $data['events']);
    }

    public function testSubscriptionsReportWhetherAuthenticationIsConfigured(): void
    {
        putenv('MSGPIT_SPRYNG_DLR_URL=http://web/sms-status.php');
        putenv('MSGPIT_SPRYNG_DLR_HEADER=X-InvalPool-Webhook');
        putenv('MSGPIT_SPRYNG_DLR_SECRET=shared');

        $data = $this->json('GET', '/v2/webhooks/subscriptions')['data'];

        self::assertIsArray($data);
        self::assertIsArray($data['events']);
        self::assertIsArray($data['events'][0]);
        self::assertTrue($data['events'][0]['requiresAuthentication']);
    }

    public function testSubscribingIsAcceptedWithACreatedStatus(): void
    {
        self::assertSame(201, $this->call('POST', '/v2/webhooks/subscriptions')->response->status);
    }

    public function testSettingAnAuthenticationMethodIsAccepted(): void
    {
        self::assertSame(200, $this->call('PUT', '/v2/webhooks/authentication-methods')->response->status);
    }

    public function testUnsubscribingAnswersNoContent(): void
    {
        $capture = $this->call('DELETE', '/v2/webhooks/events/message-delivered');

        self::assertSame(204, $capture->response->status);
        self::assertSame('', $capture->response->body);
    }

    public function testTheWebhookEndpointsStoreNothing(): void
    {
        foreach ([
            ['GET', '/v2/webhooks/events'],
            ['GET', '/v2/webhooks/subscriptions'],
            ['POST', '/v2/webhooks/subscriptions'],
            ['PUT', '/v2/webhooks/authentication-methods'],
            ['DELETE', '/v2/webhooks/events/message-delivered'],
        ] as [$method, $path]) {
            self::assertSame([], $this->call($method, $path)->messages, "{$method} {$path}");
        }
    }

    public function testTheyAllRequireAKey(): void
    {
        foreach ([
            ['GET', '/v2/webhooks/events'],
            ['GET', '/v2/webhooks/subscriptions'],
            ['POST', '/v2/webhooks/subscriptions'],
            ['PUT', '/v2/webhooks/authentication-methods'],
            ['DELETE', '/v2/webhooks/events/message-delivered'],
        ] as [$method, $path]) {
            $request = new Request($method, $path);

            foreach ((new SpryngProvider())->routes() as $route) {
                $params = $route->match($method, $path);

                if ($params !== null) {
                    self::assertSame(401, ($route->handler)($request, $params)->response->status, "{$method} {$path}");
                }
            }
        }
    }
}
