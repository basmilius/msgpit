<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Mail\Dkim\Canonicalization;
use Msgpit\Mail\Dkim\Header;
use Msgpit\Mail\Dkim\PublicKeyRecord;
use Msgpit\Mail\Dkim\Signature;
use PHPUnit\Framework\TestCase;

/** The example message from RFC 6376 3.4.6, which exists to pin exactly these two algorithms. */
final class DkimCanonicalizationTest extends TestCase
{
    private const HEAD = "A: X\r\nB : Y\t\r\n\tZ  ";
    private const BODY = " C \r\nD \t E\r\n\r\n\r\n";

    public function testRelaxedHeadersFollowRfc6376(): void
    {
        $canon = Canonicalization::Relaxed;
        $headers = Header::all(self::HEAD);

        self::assertSame('a:X', $canon->header($headers[0]->raw));
        self::assertSame('b:Y Z', $canon->header($headers[1]->raw));
    }

    public function testSimpleHeadersAreLeftAlone(): void
    {
        $canon = Canonicalization::Simple;
        $headers = Header::all(self::HEAD);

        self::assertSame('A: X', $canon->header($headers[0]->raw));
        self::assertSame("B : Y\t\r\n\tZ  ", $canon->header($headers[1]->raw));
    }

    public function testRelaxedBodyFollowsRfc6376(): void
    {
        self::assertSame(" C\r\nD E\r\n", Canonicalization::Relaxed->body(self::BODY));
    }

    public function testSimpleBodyOnlyDropsTrailingEmptyLines(): void
    {
        self::assertSame(" C \r\nD \t E\r\n", Canonicalization::Simple->body(self::BODY));
    }

    public function testAnEmptyBodyIsACrlfInSimpleAndNothingInRelaxed(): void
    {
        self::assertSame("\r\n", Canonicalization::Simple->body(''));
        self::assertSame('', Canonicalization::Relaxed->body(''));
        self::assertSame('', Canonicalization::Relaxed->body("\r\n \r\n\t\r\n"));
    }

    public function testABodyWithoutATrailingCrlfGetsOne(): void
    {
        self::assertSame("hallo\r\n", Canonicalization::Simple->body('hallo'));
        self::assertSame("hallo\r\n", Canonicalization::Relaxed->body('hallo  '));
    }

    public function testRelaxedCollapsesWhitespaceInsideALine(): void
    {
        self::assertSame("a b c\r\n", Canonicalization::Relaxed->body("a \t b   c \t\r\n"));
    }

    public function testHeadersKeepTheirFoldingAndOrder(): void
    {
        $headers = Header::all("From: a@example.test\r\nReceived: one\r\nReceived: two\r\n by three");

        self::assertSame(['from', 'received', 'received'], array_map(static fn (Header $h): string => $h->name, $headers));
        self::assertSame("Received: two\r\n by three", $headers[2]->raw);
    }

    public function testItCutsTheSignatureValueOutOfTheHeader(): void
    {
        $raw = "DKIM-Signature: v=1; a=rsa-sha256; d=a.test; s=k; h=from; bh=AAA=;\r\n b=Zm9vYmFy; t=1";
        $signature = Signature::parse($raw);

        self::assertNotNull($signature);
        self::assertSame("DKIM-Signature: v=1; a=rsa-sha256; d=a.test; s=k; h=from; bh=AAA=;\r\n b=; t=1", $signature->headerWithoutSignature());
        self::assertSame('Zm9vYmFy', $signature->signature);
    }

    public function testItReadsTheKeyRecord(): void
    {
        $record = PublicKeyRecord::parse('v=DKIM1; k=rsa; h=sha256; t=y; p=QUJD');

        self::assertNotNull($record);
        self::assertSame('rsa', $record->keyType);
        self::assertSame('QUJD', $record->publicKey);
        self::assertTrue($record->testing);
        self::assertTrue($record->allows('sha256'));
        self::assertFalse($record->allows('sha1'));
        self::assertFalse($record->revoked());
    }

    public function testAnEmptyKeyIsRevokedAndAKeyWithoutHashesAllowsEverything(): void
    {
        $revoked = PublicKeyRecord::parse('v=DKIM1; k=rsa; p=');
        $open = PublicKeyRecord::parse('k=rsa; p=QUJD');

        self::assertNotNull($revoked);
        self::assertTrue($revoked->revoked());
        self::assertNotNull($open);
        self::assertTrue($open->allows('sha1'));
    }

    public function testItRefusesAKeyRecordItCannotTrust(): void
    {
        self::assertNull(PublicKeyRecord::parse('v=DKIM42; k=rsa; p=QUJD'));
        self::assertNull(PublicKeyRecord::parse('v=DKIM1; k=rsa'));
        self::assertNull(PublicKeyRecord::parse('v=DKIM1; p=QUJD; p=QUJE'));
    }

    public function testItRefusesASignatureMissingARequiredTag(): void
    {
        self::assertNull(Signature::parse('DKIM-Signature: v=1; a=rsa-sha256; d=a.test'));
        self::assertNull(Signature::parse('DKIM-Signature: v=1; a=rsa-sha256; d=a.test; s=k; h=from; bh=AAA=; b=AAA=; t=nope'));
    }
}
