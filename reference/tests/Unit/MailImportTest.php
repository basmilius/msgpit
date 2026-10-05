<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Api\Api;
use Msgpit\Core\Docs;
use Msgpit\Core\DlrDispatcher;
use Msgpit\Core\MailCapture;
use Msgpit\Core\ProviderRegistry;
use Msgpit\Core\Storage;
use Msgpit\Http\Request;
use Msgpit\Http\Response;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Importing a .eml that was dragged out of a mail client. The point of the feature is validation:
 * run a message that was actually sent through the spam and html checks. It never travelled over
 * our SMTP server, so there is no envelope, and the difference has to stay visible.
 */
final class MailImportTest extends TestCase
{
    private Storage $storage;

    private Api $api;

    protected function setUp(): void
    {
        $this->storage = new Storage(new PDO('sqlite::memory:'));
        $this->api = new Api(
            $this->storage,
            new ProviderRegistry([]),
            new DlrDispatcher($this->storage),
            new Docs(dirname(__DIR__, 2) . '/docs'),
            capture: new MailCapture($this->storage),
        );
    }

    private function post(string $body, ?string $filename = null): Response
    {
        $response = $this->api->handle(new Request(
            'POST',
            '/api/messages/import',
            $filename === null ? [] : ['x-msgpit-filename' => $filename],
            [],
            $body,
        ));

        self::assertNotNull($response, 'No route for POST /api/messages/import');

        return $response;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/mime/' . $name);
    }

    public function testAnImportedFileBecomesAMessage(): void
    {
        $response = $this->post($this->fixture('plain-text.eml'), 'bewaard.eml');

        self::assertSame(201, $response->status);
        self::assertSame(['imported' => 1], json_decode($response->body, true));
        self::assertCount(1, $this->storage->all());
    }

    /** Without an envelope, the headers are the only record of who the message was for. */
    public function testTheRecipientsComeFromTheHeaders(): void
    {
        $raw = implode("\r\n", [
            'From: Sender <sender@example.test>',
            'To: eerste@example.test, Tweede <tweede@example.test>',
            'Cc: derde@example.test',
            'Subject: Drie ontvangers',
            '',
            'Tekst.',
        ]);

        $this->post($raw);
        $message = $this->storage->all()[0];

        foreach (['eerste@example.test', 'tweede@example.test', 'derde@example.test'] as $address) {
            self::assertStringContainsString($address, $message->to);
        }
    }

    /**
     * Everywhere else a message to three people is three rows, because that is three deliveries
     * and each can fail on its own. An import already arrived, once, so three identical rows would
     * be noise.
     */
    public function testAMessageToSeveralPeopleIsOneRow(): void
    {
        $raw = "From: a@example.test\r\nTo: een@example.test, twee@example.test\r\nCc: drie@example.test\r\n\r\nTekst.";

        self::assertSame(['imported' => 1], json_decode($this->post($raw)->body, true));
        self::assertCount(1, $this->storage->all());
        self::assertSame(3, $this->storage->all()[0]->meta['recipients'] ?? null);
    }

    /** Joined rather than dropped, because the recipient filter matches on a substring. */
    public function testFilteringByAnyOfTheRecipientsFindsIt(): void
    {
        $this->post("From: a@example.test\r\nTo: een@example.test, twee@example.test\r\n\r\nTekst.");

        foreach (['een@example.test', 'twee@example.test'] as $address) {
            self::assertCount(1, $this->storage->all(['to' => $address]), $address);
        }
    }

    public function testAnImportIsMarkedAsOneAndKeepsItsFilename(): void
    {
        $this->post($this->fixture('plain-text.eml'), 'van-de-mac.eml');
        $message = $this->storage->all()[0];

        self::assertSame('import', $message->provider, 'An import did not arrive over SMTP');
        self::assertTrue($message->meta['imported'] ?? false);
        self::assertSame('van-de-mac.eml', $message->meta['filename'] ?? null);
    }

    /** A received mail must not grow the flag, or the UI would label everything an import. */
    public function testAReceivedMailIsNotMarkedAsAnImport(): void
    {
        (new MailCapture($this->storage))->capture(new \Msgpit\Smtp\Envelope(
            'sender@example.test',
            ['raymond@example.test'],
            $this->fixture('plain-text.eml'),
        ));

        $message = $this->storage->all()[0];

        self::assertSame('smtp', $message->provider);
        self::assertArrayNotHasKey('imported', $message->meta);
    }

    /** Claiming an envelope that never existed would be a lie about how the message arrived. */
    public function testAnImportReportsNoEnvelope(): void
    {
        $this->post($this->fixture('plain-text.eml'));
        $meta = $this->storage->all()[0]->meta;

        self::assertArrayNotHasKey('envelopeSender', $meta);
        self::assertArrayNotHasKey('envelopeRecipients', $meta);
    }

    /** A message nobody can be found for is still worth checking for spam. */
    public function testAMessageWithoutRecipientsIsStillStored(): void
    {
        $this->post("From: a@example.test\r\nSubject: Geen ontvanger\r\n\r\nTekst.");
        $message = $this->storage->all()[0];

        self::assertSame('(no recipient)', $message->to);
    }

    /** The whole point is running the checks, so the parts have to be there. */
    public function testAnImportIsParsedLikeAnyOtherMail(): void
    {
        $this->post($this->fixture('nested-with-inline-image-and-attachment.eml'));
        $id = $this->storage->all()[0]->id;

        self::assertCount(4, $this->storage->parts($id));

        $response = $this->api->handle(new Request('GET', '/api/messages/' . $id));

        self::assertNotNull($response);

        $detail = json_decode($response->body, true);

        self::assertIsArray($detail);
        self::assertIsString($detail['html']);
        self::assertIsArray($detail['headers']);
    }

    public function testAnEmptyFileIsRefused(): void
    {
        self::assertSame(400, $this->post("\r\n \r\n")->status);
        self::assertSame([], $this->storage->all());
    }

    public function testAFileBeyondTheCeilingIsRefused(): void
    {
        self::assertSame(413, $this->post(str_repeat('x', 31 * 1024 * 1024))->status);
    }

    /** The UI percent encodes the name, because a header carries latin-1 and mail files have accents. */
    public function testAPercentEncodedFilenameIsDecoded(): void
    {
        $this->post($this->fixture('plain-text.eml'), 'evaluatie%20caf%C3%A9.eml');

        self::assertSame('evaluatie café.eml', $this->storage->all()[0]->meta['filename'] ?? null);
    }

    /** Dropping a file with no name at all should not store the string "null" as one. */
    public function testAnImportWithoutAFilenameHasNoFilename(): void
    {
        $this->post($this->fixture('plain-text.eml'));

        self::assertArrayNotHasKey('filename', $this->storage->all()[0]->meta);
    }
}
