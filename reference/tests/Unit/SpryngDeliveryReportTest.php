<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Channel;
use Msgpit\Core\DeliveryStatus;
use Msgpit\Core\Message;
use Msgpit\Provider\Spryng\SpryngProvider;
use PHPUnit\Framework\TestCase;

final class SpryngDeliveryReportTest extends TestCase
{
    /** @var list<string> */
    private array $set = [];

    protected function tearDown(): void
    {
        foreach ($this->set as $name) {
            putenv($name);
        }

        $this->set = [];
    }

    private function env(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $this->set[] = $name;
    }

    /** @param array<string, mixed> $meta */
    private function message(array $meta = []): Message
    {
        return Message::create(
            batchId: '80997ed3-aefb-4815-b0cb-37b2e2367845',
            provider: 'spryng',
            channel: Channel::Sms,
            to: '+31612345678',
            body: 'Your code is 123456',
            from: 'Acme',
            providerRef: '6e5676b3-892c-4704-896d-fd3e4f18c095',
            meta: $meta,
        );
    }

    /**
     * The first (and only) message in the callback payload.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function reported(DeliveryStatus $status = DeliveryStatus::Delivered, array $meta = []): array
    {
        $payload = $this->payload($status, $meta);

        self::assertIsArray($payload['Messages']);
        self::assertIsArray($payload['Messages'][0]);

        /** @var array<string, mixed> $reported */
        $reported = $payload['Messages'][0];

        return $reported;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function payload(DeliveryStatus $status = DeliveryStatus::Delivered, array $meta = []): array
    {
        $callback = (new SpryngProvider())->deliveryReport($this->message($meta), $status);

        self::assertNotNull($callback);

        $decoded = json_decode($callback->body, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testWithoutACallbackUrlThereIsNothingToSend(): void
    {
        putenv('MSGPIT_SPRYNG_DLR_URL');

        self::assertNull((new SpryngProvider())->deliveryReport($this->message(), DeliveryStatus::Delivered));
    }

    public function testItPostsToTheConfiguredUrl(): void
    {
        $this->env('MSGPIT_SPRYNG_DLR_URL', 'http://web/sms-status.php');

        $callback = (new SpryngProvider())->deliveryReport($this->message(), DeliveryStatus::Delivered);

        self::assertNotNull($callback);
        self::assertSame('POST', $callback->method);
        self::assertSame('http://web/sms-status.php', $callback->url);
        self::assertSame('application/json', $callback->headers['Content-Type']);
    }

    public function testThePayloadIsPascalCaseAndDropsThePlus(): void
    {
        $this->env('MSGPIT_SPRYNG_DLR_URL', 'http://web/sms-status.php');

        $payload = $this->payload();
        $reported = $this->reported();

        self::assertSame('80997ed3-aefb-4815-b0cb-37b2e2367845', $payload['RequestId']);
        self::assertSame('Delivered', $payload['Status']);
        self::assertSame('6e5676b3-892c-4704-896d-fd3e4f18c095', $reported['MessageId']);
        self::assertSame('31612345678', $reported['Msisdn'], 'Spryng drops the leading plus');
        self::assertSame('GSM', $reported['MessageCoding']);
        self::assertSame(1, $reported['MessageParts']);
    }

    public function testAFailureReportsAReason(): void
    {
        $this->env('MSGPIT_SPRYNG_DLR_URL', 'http://web/sms-status.php');

        self::assertSame('Failed', $this->payload(DeliveryStatus::Failed)['Status']);
        self::assertSame('NetworkFailed', $this->reported(DeliveryStatus::Failed)['Reason']);
    }

    /**
     * Apps key their own records off the metaData they sent with the message. Dropping it makes
     * every report unmatchable, which is exactly the bug this covers.
     */
    public function testItReturnsTheRecipientMetadata(): void
    {
        $this->env('MSGPIT_SPRYNG_DLR_URL', 'http://web/sms-status.php');

        $reported = $this->reported(meta: ['recipientMetaData' => ['invalpool' => 'demo', 'sms_id' => '4821']]);

        self::assertSame(['invalpool' => 'demo', 'sms_id' => '4821'], $reported['Metadata']);
    }

    public function testMetadataIsAnObjectWhenThereWasNone(): void
    {
        $this->env('MSGPIT_SPRYNG_DLR_URL', 'http://web/sms-status.php');

        $callback = (new SpryngProvider())->deliveryReport($this->message(), DeliveryStatus::Delivered);

        self::assertNotNull($callback);
        // An empty PHP array would encode as [], and a client expecting an object chokes on that.
        self::assertStringContainsString('"Metadata":{}', $callback->body);
    }

    public function testItSendsTheConfiguredAuthenticationHeader(): void
    {
        $this->env('MSGPIT_SPRYNG_DLR_URL', 'http://web/sms-status.php');
        $this->env('MSGPIT_SPRYNG_DLR_HEADER', 'X-InvalPool-Webhook');
        $this->env('MSGPIT_SPRYNG_DLR_SECRET', 'a-shared-secret');

        $callback = (new SpryngProvider())->deliveryReport($this->message(), DeliveryStatus::Delivered);

        self::assertNotNull($callback);
        self::assertSame('a-shared-secret', $callback->headers['X-InvalPool-Webhook']);
    }

    public function testHalfConfiguredAuthenticationIsIgnored(): void
    {
        $this->env('MSGPIT_SPRYNG_DLR_URL', 'http://web/sms-status.php');
        $this->env('MSGPIT_SPRYNG_DLR_HEADER', 'X-InvalPool-Webhook');
        putenv('MSGPIT_SPRYNG_DLR_SECRET');

        $callback = (new SpryngProvider())->deliveryReport($this->message(), DeliveryStatus::Delivered);

        self::assertNotNull($callback);
        self::assertArrayNotHasKey('X-InvalPool-Webhook', $callback->headers);
    }
}
