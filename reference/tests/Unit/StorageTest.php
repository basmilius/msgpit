<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Channel;
use Msgpit\Core\Encoding;
use Msgpit\Core\DeliveryStatus;
use Msgpit\Core\Message;
use Msgpit\Core\MessageStatus;
use Msgpit\Core\RawRequest;
use Msgpit\Core\Scenario;
use Msgpit\Core\Storage;
use PDO;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    private Storage $storage;

    protected function setUp(): void
    {
        $this->storage = new Storage(new PDO('sqlite::memory:'));
    }

    private function message(string $to = '+31612345678', string $body = 'Hello'): Message
    {
        return Message::create(
            batchId: 'batch-1',
            provider: 'spryng',
            channel: Channel::Sms,
            to: $to,
            body: $body,
            from: 'Acme',
            providerRef: 'ref-1',
            meta: ['characterSet' => 'Auto'],
        );
    }

    private function raw(): RawRequest
    {
        return new RawRequest('POST', '/spryng/v2/messages', ['x-api-key' => '****'], '{}');
    }

    public function testItStoresAndReadsBackAMessage(): void
    {
        $message = $this->message();
        $this->storage->store([$message], $this->raw());

        $stored = $this->storage->find($message->id);

        self::assertNotNull($stored);
        self::assertSame('+31612345678', $stored->to);
        self::assertSame('Acme', $stored->from);
        self::assertSame('spryng', $stored->provider);
        self::assertSame(Channel::Sms, $stored->channel);
        self::assertNotNull($stored->segmentInfo);
        self::assertSame(Encoding::Gsm7, $stored->segmentInfo->encoding);
        self::assertSame(1, $stored->segmentInfo->segments);
        self::assertSame(['characterSet' => 'Auto'], $stored->meta);
    }

    public function testItStoresOneRowPerRecipientSharingABatch(): void
    {
        $this->storage->store([$this->message('+31611111111'), $this->message('+31622222222')], $this->raw());

        $stored = $this->storage->all();

        self::assertCount(2, $stored);
        self::assertSame(['batch-1', 'batch-1'], array_map(static fn (Message $m): string => $m->batchId, $stored));
    }

    public function testItKeepsTheRawRequestNextToTheMessage(): void
    {
        $message = $this->message();
        $this->storage->store([$message], $this->raw());

        self::assertStringContainsString('POST /spryng/v2/messages', (string) $this->storage->rawRequest($message->id));
        self::assertStringContainsString('X-Api-Key: ****', (string) $this->storage->rawRequest($message->id));
    }

    public function testItFiltersOnProviderChannelAndRecipient(): void
    {
        $this->storage->store([$this->message('+31611111111'), $this->message('+31622222222')], $this->raw());

        self::assertCount(2, $this->storage->all(['provider' => 'spryng']));
        self::assertCount(0, $this->storage->all(['provider' => 'messagebird']));
        self::assertCount(2, $this->storage->all(['channel' => 'sms']));
        self::assertCount(1, $this->storage->all(['to' => '1111']));
    }

    public function testItUpdatesStatus(): void
    {
        $message = $this->message();
        $this->storage->store([$message], $this->raw());

        $this->storage->updateStatus($message->id, MessageStatus::Delivered);

        self::assertSame(MessageStatus::Delivered, $this->storage->find($message->id)?->status);
    }

    public function testItRecordsDeliveryReports(): void
    {
        $message = $this->message();
        $this->storage->store([$message], $this->raw());

        $this->storage->recordDeliveryReport(
            $message->id,
            DeliveryStatus::Delivered,
            'http://web/webhooks/spryng',
            '{"Status":"Delivered"}',
            200,
            'ok',
        );

        $reports = $this->storage->deliveryReports($message->id);

        self::assertCount(1, $reports);
        self::assertSame('delivered', $reports[0]['status']);
        self::assertSame(200, $reports[0]['responseStatus']);
        self::assertSame('ok', $reports[0]['responseBody']);
    }

    public function testClearRemovesEverything(): void
    {
        $message = $this->message();
        $this->storage->store([$message], $this->raw());
        $this->storage->recordDeliveryReport($message->id, DeliveryStatus::Failed, 'http://web', '{}', 500, 'no');

        $this->storage->clear();

        self::assertSame([], $this->storage->all());
        self::assertSame([], $this->storage->deliveryReports($message->id));
    }

    public function testItPrunesBeyondTheLimit(): void
    {
        $storage = new Storage(new PDO('sqlite::memory:'), maxMessages: 3);

        foreach (range(1, 5) as $index) {
            $storage->store([$this->message("+3161111111{$index}")], $this->raw());
        }

        self::assertCount(3, $storage->all());
    }

    public function testEveryStoredMessageEmitsAnEvent(): void
    {
        $this->storage->store([$this->message('+31611111111'), $this->message('+31622222222')], $this->raw());

        $events = $this->storage->eventsSince(0);

        self::assertCount(2, $events);
        self::assertSame('message', $events[0]['type']);
        self::assertSame($this->storage->latestSeq(), $events[1]['seq']);
    }

    public function testAStatusChangeEmitsAnEvent(): void
    {
        $message = $this->message();
        $this->storage->store([$message], $this->raw());
        $seq = $this->storage->latestSeq();

        $this->storage->updateStatus($message->id, MessageStatus::Failed);

        $events = $this->storage->eventsSince($seq);

        self::assertCount(1, $events);
        self::assertSame('status', $events[0]['type']);
        self::assertSame($message->id, $events[0]['messageId']);
    }

    public function testANewMessageIsUnread(): void
    {
        $message = $this->message();
        $this->storage->store([$message], $this->raw());

        self::assertNull($this->storage->find($message->id)?->readAt);
        self::assertSame(1, $this->storage->unreadCount());
    }

    public function testMarkingAMessageReadLowersTheCount(): void
    {
        $this->storage->store([$this->message('+31611111111'), $this->message('+31622222222')], $this->raw());
        $first = $this->storage->all()[0];

        $this->storage->markRead($first->id);

        self::assertNotNull($this->storage->find($first->id)?->readAt);
        self::assertSame(1, $this->storage->unreadCount());
    }

    public function testMarkingReadTwiceEmitsOneEvent(): void
    {
        $message = $this->message();
        $this->storage->store([$message], $this->raw());
        $seq = $this->storage->latestSeq();

        $this->storage->markRead($message->id);
        $this->storage->markRead($message->id);

        self::assertCount(1, $this->storage->eventsSince($seq), 'A no-op must not wake other tabs');
    }

    public function testMarkAllRead(): void
    {
        $this->storage->store([$this->message('+31611111111'), $this->message('+31622222222')], $this->raw());

        $this->storage->markAllRead();

        self::assertSame(0, $this->storage->unreadCount());
    }

    public function testMarkAllReadOnAnEmptyInboxEmitsNothing(): void
    {
        $seq = $this->storage->latestSeq();

        $this->storage->markAllRead();

        self::assertSame([], $this->storage->eventsSince($seq));
    }

    public function testTheReadStateSurvivesAStatusChange(): void
    {
        $message = $this->message();
        $this->storage->store([$message], $this->raw());
        $this->storage->markRead($message->id);

        $this->storage->updateStatus($message->id, MessageStatus::Delivered);

        self::assertNotNull($this->storage->find($message->id)?->readAt);
    }

    public function testTheScenarioIsOneShot(): void
    {
        $this->storage->setScenario(Scenario::RateLimited);

        self::assertSame(Scenario::RateLimited, $this->storage->consumeScenario());
        self::assertNull($this->storage->consumeScenario());
    }

    public function testSettingTheScenarioTwiceReplacesIt(): void
    {
        $this->storage->setScenario(Scenario::RateLimited);
        $this->storage->setScenario(Scenario::ServerError);

        self::assertSame(Scenario::ServerError, $this->storage->consumeScenario());
    }

    public function testTheCacheReturnsWhatWasPutIn(): void
    {
        $this->storage->cache('dns:txt:example.test', '["v=spf1 -all"]', 300);

        self::assertSame('["v=spf1 -all"]', $this->storage->cached('dns:txt:example.test'));
        self::assertNull($this->storage->cached('dns:txt:other.test'));
    }

    /** An answer past its TTL is gone, or a record that changed would never be seen to change. */
    public function testAnExpiredEntryIsNotReturned(): void
    {
        $this->storage->cache('dns:txt:example.test', 'oud', -1);

        self::assertNull($this->storage->cached('dns:txt:example.test'));
    }

    public function testWritingTheSameKeyTwiceReplacesIt(): void
    {
        $this->storage->cache('dns:txt:example.test', 'eerst', 300);
        $this->storage->cache('dns:txt:example.test', 'daarna', 300);

        self::assertSame('daarna', $this->storage->cached('dns:txt:example.test'));
    }
}
