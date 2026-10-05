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
use Msgpit\Smtp\Envelope;
use PDO;
use PHPUnit\Framework\TestCase;

final class MailApiTest extends TestCase
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
        );
    }

    private function receive(string $fixture = 'nested-with-inline-image-and-attachment.eml'): string
    {
        (new MailCapture($this->storage))->capture(new Envelope(
            'sender@example.test',
            ['raymond@example.test'],
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/mime/' . $fixture),
        ));

        return $this->storage->all()[0]->id;
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        $response = $this->api->handle(new Request('GET', '/api' . $path, [], $query));

        self::assertNotNull($response, "No route for GET /api{$path}");

        return $response;
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $decoded = json_decode($this->get($path)->body, true);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testTheDetailCarriesThePartsAndBothBodies(): void
    {
        $detail = $this->json('/messages/' . $this->receive());

        self::assertIsArray($detail['parts']);
        self::assertCount(4, $detail['parts']);
        self::assertIsString($detail['html']);
        self::assertIsString($detail['text']);
        self::assertStringContainsString('cid:', $detail['html'], 'The UI resolves cid itself');
    }

    /** An SMS has no MIME parts, and the detail should not pretend otherwise. */
    public function testANonMailMessageHasNoParts(): void
    {
        $this->receive();
        $detail = $this->json('/messages/' . $this->storage->all()[0]->id);

        self::assertArrayHasKey('parts', $detail);

        $storage = new Storage(new PDO('sqlite::memory:'));
        $api = new Api($storage, new ProviderRegistry([]), new DlrDispatcher($storage), new Docs(dirname(__DIR__, 2) . '/docs'));

        $storage->store(
            [\Msgpit\Core\Message::create('batch', 'spryng', \Msgpit\Core\Channel::Sms, '+31612345678', 'Hoi')],
            new \Msgpit\Core\RawRequest('POST', '/spryng/v2/messages', [], '{}'),
        );

        $response = $api->handle(new Request('GET', '/api/messages/' . $storage->all()[0]->id));

        self::assertNotNull($response);
        $decoded = json_decode($response->body, true);

        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('parts', $decoded);
    }

    /**
     * Mail analysis lives in the headers: a missing Date, a Return-Path that disagrees with From,
     * a List-Unsubscribe that never made it in. So all of them come back, not the handful the
     * summary keeps.
     */
    public function testEveryHeaderComesBack(): void
    {
        $raw = implode("\r\n", [
            'Return-Path: <bounce@example.test>',
            'From: Sender <sender@example.test>',
            'To: raymond@example.test',
            'Bcc: logs@example.test',
            'Subject: Met veel headers',
            'Date: Mon, 14 Sep 2026 11:00:00 +0200',
            'List-Unsubscribe: <mailto:uit@example.test>',
            'Auto-Submitted: auto-generated',
            'X-Mailer: msgpit test',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Tekst.',
        ]);

        (new MailCapture($this->storage))->capture(new Envelope('bounce@example.test', ['raymond@example.test'], $raw));

        $headers = $this->json('/messages/' . $this->storage->all()[0]->id)['headers'];

        self::assertIsArray($headers);

        foreach (['return-path', 'from', 'to', 'bcc', 'subject', 'date', 'list-unsubscribe', 'auto-submitted', 'x-mailer'] as $name) {
            self::assertArrayHasKey($name, $headers, "{$name} should be reported");
        }

        self::assertSame('logs@example.test', $headers['bcc'], 'A Bcc is only visible here');
    }

    public function testHeaderNamesAreLowercasedAndValuesDecoded(): void
    {
        $id = $this->receive('folded-subject.eml');
        $headers = $this->json("/messages/{$id}")['headers'];

        self::assertIsArray($headers);
        self::assertArrayHasKey('subject', $headers);

        $subject = $headers['subject'];

        self::assertIsString($subject);
        self::assertStringContainsString('—', $subject, 'Encoded words are decoded');
        self::assertStringNotContainsString('=?utf-8?', $subject);
    }

    /** An SMS has no headers to report, and should not grow an empty field for them. */
    public function testANonMailMessageHasNoHeaders(): void
    {
        $storage = new Storage(new PDO('sqlite::memory:'));
        $api = new Api($storage, new ProviderRegistry([]), new DlrDispatcher($storage), new Docs(dirname(__DIR__, 2) . '/docs'));

        $storage->store(
            [\Msgpit\Core\Message::create('batch', 'spryng', \Msgpit\Core\Channel::Sms, '+31612345678', 'Hoi')],
            new \Msgpit\Core\RawRequest('POST', '/spryng/v2/messages', [], '{}'),
        );

        $response = $api->handle(new Request('GET', '/api/messages/' . $storage->all()[0]->id));

        self::assertNotNull($response);

        $decoded = json_decode($response->body, true);

        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('headers', $decoded);
    }

    public function testAPartIsServedWithItsOwnContentType(): void
    {
        $id = $this->receive();
        $parts = $this->storage->parts($id);
        $image = array_values(array_filter($parts, static fn (array $p): bool => $p['disposition'] === 'inline'))[0];

        $response = $this->get("/messages/{$id}/parts/{$image['id']}");

        self::assertSame(200, $response->status);
        self::assertSame('image/png', $response->headers['Content-Type']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertNotSame('', $response->body);
    }

    /**
     * A part names its own content type and the sender chose it. Anything the browser might run in
     * our origin has to come back as a download instead.
     */
    public function testADangerousContentTypeIsNeutralised(): void
    {
        $id = $this->receive('html-only.eml');
        $part = $this->storage->parts($id)[0];

        $response = $this->get("/messages/{$id}/parts/{$part['id']}");

        self::assertSame('application/octet-stream', $response->headers['Content-Type']);
    }

    public function testAnAttachmentIsOfferedAsADownload(): void
    {
        $id = $this->receive('attachment-utf8-filename.eml');
        $attachment = array_values(array_filter(
            $this->storage->parts($id),
            static fn (array $p): bool => $p['disposition'] === 'attachment',
        ))[0];

        $response = $this->get("/messages/{$id}/parts/{$attachment['id']}", ['download' => '1']);
        $disposition = $response->headers['Content-Disposition'];

        self::assertStringStartsWith('attachment;', $disposition);
        // Both spellings: the plain one for old clients, the encoded one for the real name.
        self::assertStringContainsString('filename="evaluatie caf', $disposition);
        self::assertStringContainsString("filename*=UTF-8''evaluatie%20caf%C3%A9.pdf", $disposition);
    }

    public function testAnUnknownPartIsNotFound(): void
    {
        $id = $this->receive();

        self::assertSame(404, $this->get("/messages/{$id}/parts/does-not-exist")->status);
    }

    /** Part ids are unguessable, but the message they belong to still has to match. */
    public function testAPartOfAnotherMessageIsNotServed(): void
    {
        $first = $this->receive();

        (new MailCapture($this->storage))->capture(new Envelope(
            'sender@example.test',
            ['ander@example.test'],
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/mime/plain-text.eml'),
        ));

        $other = array_values(array_filter(
            $this->storage->all(),
            static fn ($message): bool => $message->id !== $first,
        ))[0];

        $partOfOther = $this->storage->parts($other->id)[0];

        self::assertSame(404, $this->get("/messages/{$first}/parts/{$partOfOther['id']}")->status);
    }

    public function testPartsAreNotCached(): void
    {
        $id = $this->receive();
        $part = $this->storage->parts($id)[0];

        self::assertSame('no-store', $this->get("/messages/{$id}/parts/{$part['id']}")->headers['Cache-Control']);
    }
}
