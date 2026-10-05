<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Mime\ParsedMessage;
use Msgpit\Mime\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MimeParserTest extends TestCase
{
    private function parse(string $fixture): ParsedMessage
    {
        $path = dirname(__DIR__) . '/fixtures/mime/' . $fixture;

        self::assertFileExists($path);

        return Parser::parse((string) file_get_contents($path));
    }

    public function testPlainText(): void
    {
        $message = $this->parse('plain-text.eml');

        self::assertSame('Probe: werkt een kale PHP SMTP-server?', $message->subject);
        self::assertSame('ClubFin <hello@example.com>', $message->from);
        self::assertCount(1, $message->parts);
        self::assertNull($message->html());
        // quoted-printable decoded, so the accents survive.
        self::assertSame('Hallo vanuit Laravel, met een accent: één regel.', $message->text());
    }

    public function testHtmlOnly(): void
    {
        $message = $this->parse('html-only.eml');

        self::assertNull($message->text());
        self::assertStringContainsString('<h1>Welkom</h1>', (string) $message->html());
        // A list needs something readable even when there is no text part.
        self::assertStringContainsString('Je account is geactiveerd.', $message->preview());
    }

    public function testAlternativePrefersTheTextPart(): void
    {
        $message = $this->parse('alternative.eml');

        self::assertCount(2, $message->parts);
        self::assertStringContainsString('Klik op de link', (string) $message->text());
        self::assertStringContainsString('<a href=', (string) $message->html());
        self::assertStringStartsWith('Klik op de link', $message->preview());
    }

    public function testNestedMultipartWithInlineImageAndAttachment(): void
    {
        $message = $this->parse('nested-with-inline-image-and-attachment.eml');

        self::assertSame('Aanstelling bevestigd — Jansen (café)', $message->subject);
        self::assertCount(4, $message->parts, 'The tree is flattened to its leaves');

        self::assertNotNull($message->text());
        self::assertNotNull($message->html());
        self::assertCount(1, $message->attachments());
        self::assertCount(1, $message->inlineParts());

        self::assertSame('evaluatieformulier.pdf', $message->attachments()[0]->filename);
        self::assertSame('logo.png', $message->inlineParts()[0]->filename);
    }

    /** The html points at the image by cid, so it has to be findable that way. */
    public function testAnInlineImageIsFoundByItsContentId(): void
    {
        $message = $this->parse('nested-with-inline-image-and-attachment.eml');
        $inline = $message->inlineParts()[0];

        self::assertNotNull($inline->contentId);

        $found = $message->partByContentId($inline->contentId);

        self::assertNotNull($found);
        self::assertSame('image/png', $found->contentType);
        self::assertStringContainsString('cid:', (string) $message->html());
    }

    public function testAnInlineImageIsNotAnAttachment(): void
    {
        $message = $this->parse('nested-with-inline-image-and-attachment.eml');

        self::assertFalse($message->inlineParts()[0]->isAttachment());
        self::assertFalse($message->attachments()[0]->isInline());
    }

    public function testItConvertsOtherCharsetsToUtf8(): void
    {
        $message = $this->parse('latin1.eml');

        self::assertSame('Café bezoek', $message->subject);
        self::assertStringContainsString('café', (string) $message->text());
        self::assertTrue(mb_check_encoding((string) $message->text(), 'UTF-8'));
    }

    public function testRfc2231FilenamesAreDecoded(): void
    {
        $message = $this->parse('attachment-utf8-filename.eml');

        self::assertSame('evaluatie café.pdf', $message->attachments()[0]->filename);
        self::assertStringStartsWith('%PDF', $message->attachments()[0]->content);
    }

    /**
     * Whitespace between two encoded-words disappears rather than becoming a space. Unfolding the
     * headers yourself before decoding gets this wrong, which is how the subject ends up mangled.
     */
    public function testAFoldedEncodedSubjectIsJoinedWithoutAStraySpace(): void
    {
        $message = $this->parse('folded-subject.eml');

        self::assertSame(
            'Aanstelling bevestigd voor de week van 12 januari tot en met 19 januari — Jansen',
            $message->subject,
        );
    }

    #[DataProvider('everyFixture')]
    public function testEveryFixtureYieldsSomethingReadable(string $fixture): void
    {
        $message = $this->parse($fixture);

        self::assertNotSame('', $message->subject, 'A subject is always there');
        self::assertNotSame('', $message->from);
        self::assertNotEmpty($message->parts);
        self::assertNotSame('', $message->preview(), 'The list needs a preview for every message');

        foreach ($message->parts as $part) {
            self::assertNotSame('', $part->contentType);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function everyFixture(): iterable
    {
        foreach (glob(dirname(__DIR__) . '/fixtures/mime/*.eml') ?: [] as $path) {
            yield basename($path) => [basename($path)];
        }
    }

    public function testCrlfAndBareNewlinesParseTheSame(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__) . '/fixtures/mime/alternative.eml');

        $crlf = Parser::parse($raw);
        $lf = Parser::parse(str_replace("\r\n", "\n", $raw));

        self::assertSame($crlf->subject, $lf->subject);
        self::assertSame($crlf->text(), $lf->text());
        self::assertCount(count($crlf->parts), $lf->parts);
    }

    public function testAMessageWithoutHeadersDoesNotExplode(): void
    {
        $message = Parser::parse('');

        self::assertSame('', $message->subject);
        self::assertSame([], $message->attachments());
    }

    /** @param list<string> $expected */
    #[DataProvider('addressLists')]
    public function testItReadsAddressesFromAHeader(string $header, array $expected): void
    {
        self::assertSame($expected, ParsedMessage::addresses($header));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function addressLists(): iterable
    {
        yield 'bare' => ['raymond@example.test', ['raymond@example.test']];
        yield 'with a name' => ['Raymond <raymond@example.test>', ['raymond@example.test']];
        yield 'several' => [
            'Raymond <a@example.test>, b@example.test',
            ['a@example.test', 'b@example.test'],
        ];
        // A comma inside a quoted display name must not split the list.
        yield 'comma in the name' => [
            '"Jansen, Piet" <piet@example.test>, ander@example.test',
            ['piet@example.test', 'ander@example.test'],
        ];
    }
}
