<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Channel;
use Msgpit\Core\MailCapture;
use Msgpit\Core\Storage;
use Msgpit\Smtp\Envelope;
use PDO;
use PHPUnit\Framework\TestCase;

final class MailCaptureTest extends TestCase
{
    private Storage $storage;

    private MailCapture $capture;

    protected function setUp(): void
    {
        $this->storage = new Storage(new PDO('sqlite::memory:'));
        $this->capture = new MailCapture($this->storage);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/mime/' . $name);
    }

    /** @param list<string> $recipients */
    private function receive(string $fixture, array $recipients = ['raymond@example.test']): void
    {
        $this->capture->capture(new Envelope('sender@example.test', $recipients, $this->fixture($fixture)));
    }

    public function testItStoresAMessage(): void
    {
        $this->receive('plain-text.eml');

        $messages = $this->storage->all();

        self::assertCount(1, $messages);
        self::assertSame('smtp', $messages[0]->provider);
        self::assertSame(Channel::Email, $messages[0]->channel);
        self::assertSame('raymond@example.test', $messages[0]->to);
        self::assertSame('ClubFin <hello@example.com>', $messages[0]->from);
        self::assertStringContainsString('Hallo vanuit Laravel', $messages[0]->body);
    }

    public function testTheSubjectIsInTheMetadata(): void
    {
        $this->receive('plain-text.eml');

        self::assertSame('Probe: werkt een kale PHP SMTP-server?', $this->storage->all()[0]->meta['subject']);
    }

    /** The same rule as everywhere else: one row per recipient, sharing a batch. */
    public function testOneMessagePerRecipientSharingABatch(): void
    {
        $this->receive('plain-text.eml', ['een@example.test', 'twee@example.test', 'drie@example.test']);

        $messages = $this->storage->all();
        $batches = array_unique(array_map(static fn ($message): string => $message->batchId, $messages));

        self::assertCount(3, $messages);
        self::assertCount(1, $batches);
        self::assertSame(
            ['drie@example.test', 'twee@example.test', 'een@example.test'],
            array_map(static fn ($message): string => $message->to, $messages),
        );
    }

    /**
     * The envelope decides who gets it, not the To header. That is how delivery works, and it is
     * the only way a Bcc recipient shows up at all.
     */
    public function testTheEnvelopeDecidesTheRecipientNotTheHeader(): void
    {
        $this->receive('plain-text.eml', ['bcc@example.test']);

        $message = $this->storage->all()[0];

        self::assertSame('bcc@example.test', $message->to);
        self::assertSame('raymond@example.test', $message->meta['to'], 'The header is kept as metadata');
    }

    public function testEmailHasNoSegmentAnalysis(): void
    {
        $this->receive('plain-text.eml');

        self::assertNull($this->storage->all()[0]->segmentInfo, 'Segments are an SMS concern');
    }

    public function testItStoresEveryPart(): void
    {
        $this->receive('nested-with-inline-image-and-attachment.eml');

        $parts = $this->storage->parts($this->storage->all()[0]->id);

        self::assertCount(4, $parts);
        self::assertSame(['body', 'body', 'inline', 'attachment'], array_column($parts, 'disposition'));
        self::assertSame('evaluatieformulier.pdf', $parts[3]['filename']);
        self::assertGreaterThan(0, $parts[2]['size']);
    }

    public function testAPartCanBeReadBack(): void
    {
        $this->receive('attachment-utf8-filename.eml');

        $message = $this->storage->all()[0];
        $attachment = array_values(array_filter(
            $this->storage->parts($message->id),
            static fn (array $part): bool => $part['disposition'] === 'attachment',
        ))[0];

        $content = $this->storage->part($message->id, $attachment['id']);

        self::assertNotNull($content);
        self::assertSame('application/pdf', $content['contentType']);
        self::assertSame('evaluatie café.pdf', $content['filename']);
        self::assertStringStartsWith('%PDF', $content['content']);
    }

    /** The html says cid:something; the UI has to turn that into a part it can serve. */
    public function testAnInlinePartIsFoundByItsContentId(): void
    {
        $this->receive('nested-with-inline-image-and-attachment.eml');

        $message = $this->storage->all()[0];
        $inline = array_values(array_filter(
            $this->storage->parts($message->id),
            static fn (array $part): bool => $part['disposition'] === 'inline',
        ))[0];

        self::assertNotNull($inline['contentId']);
        self::assertSame($inline['id'], $this->storage->partByContentId($message->id, $inline['contentId']));
        self::assertSame($inline['id'], $this->storage->partByContentId($message->id, '<' . $inline['contentId'] . '>'));
    }

    public function testAPartOfAnotherMessageIsNotReachable(): void
    {
        $this->receive('nested-with-inline-image-and-attachment.eml', ['een@example.test']);
        $this->receive('plain-text.eml', ['twee@example.test']);

        $messages = $this->storage->all();
        $other = $this->storage->parts($messages[1]->id)[0];

        self::assertNull($this->storage->part($messages[0]->id, $other['id']));
    }

    public function testTheWholeMessageIsKeptAsReceived(): void
    {
        $this->receive('nested-with-inline-image-and-attachment.eml');

        $raw = (string) $this->storage->rawRequest($this->storage->all()[0]->id);

        self::assertStringContainsString('SMTP inbound', $raw);
        self::assertStringContainsString('Content-Type: multipart/mixed', $raw);
    }

    public function testClearingRemovesThePartsToo(): void
    {
        $this->receive('nested-with-inline-image-and-attachment.eml');
        $id = $this->storage->all()[0]->id;

        $this->storage->clear();

        self::assertSame([], $this->storage->parts($id));
    }

    /** Attachments are the bulk of the database, so pruning has to take them along. */
    public function testPruningRemovesTheirParts(): void
    {
        $storage = new Storage(new PDO('sqlite::memory:'), maxMessages: 2);
        $capture = new MailCapture($storage);

        $ids = [];

        foreach (['een', 'twee', 'drie', 'vier'] as $recipient) {
            $capture->capture(new Envelope(
                'sender@example.test',
                ["{$recipient}@example.test"],
                $this->fixture('nested-with-inline-image-and-attachment.eml'),
            ));
            $ids[] = $storage->all()[0]->id;
        }

        self::assertCount(2, $storage->all());
        self::assertSame([], $storage->parts($ids[0]), 'The pruned message left no parts behind');
        self::assertNotSame([], $storage->parts($ids[3]));
    }

    public function testAMessageWithNoRecipientsStoresNothing(): void
    {
        $this->capture->capture(new Envelope('sender@example.test', [], $this->fixture('plain-text.eml')));

        self::assertSame([], $this->storage->all());
    }

    public function testASpamVerdictEndsUpInTheMetadata(): void
    {
        $capture = new MailCapture($this->storage, new \Msgpit\Core\SpamAssassin('127.0.0.1:1', timeout: 1));

        $capture->capture(new Envelope('sender@example.test', ['a@example.test'], $this->fixture('plain-text.eml')));

        // Nothing listens on that port, so there is no verdict, and the message is stored anyway.
        self::assertCount(1, $this->storage->all());
        self::assertArrayNotHasKey('spam', $this->storage->all()[0]->meta);
    }

    public function testItSurvivesAMessageThatIsNotReallyMime(): void
    {
        $this->capture->capture(new Envelope('sender@example.test', ['a@example.test'], 'geen headers, geen mime'));

        $messages = $this->storage->all();

        self::assertCount(1, $messages);
        self::assertSame('sender@example.test', $messages[0]->from, 'Falls back to the envelope sender');
    }
}
