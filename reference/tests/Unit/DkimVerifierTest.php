<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Mail\Dkim\Canonicalization;
use Msgpit\Mail\Dkim\KeyLookup;
use Msgpit\Mail\Dkim\SignatureResult;
use Msgpit\Mail\Dkim\SignatureStatus;
use Msgpit\Mail\Dkim\StaticKeyLookup;
use Msgpit\Mail\Dkim\Verifier;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DkimVerifierTest extends TestCase
{
    private const DOMAIN = 'example.test';
    private const SELECTOR = 'msgpit';
    private const MESSAGE = "From: Bas <bas@example.test>\r\nTo: Raymond <raymond@example.test>\r\nSubject: Hallo\r\n\r\nDit is de body.\r\n";

    private static ?OpenSSLAsymmetricKey $privateKey = null;
    private static string $publicKey = '';

    /** One key pair for the whole class: generating RSA keys is the slow part of these tests. */
    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        self::$privateKey = $key;
        self::$publicKey = preg_replace('/(-----[A-Z ]+-----|\s)/', '', $details['key']) ?? '';
    }

    public function testItVerifiesASignatureItJustMade(): void
    {
        $result = $this->verifyOne($this->sign(self::MESSAGE));

        self::assertSame(SignatureStatus::Pass, $result->status);
        self::assertSame(self::DOMAIN, $result->domain);
        self::assertSame(self::SELECTOR, $result->selector);
        self::assertSame('rsa-sha256', $result->algorithm);
        self::assertStringContainsString('msgpit._domainkey.example.test', $result->reason);
    }

    /** @return list<array{string}> */
    public static function canonicalizations(): array
    {
        return [['relaxed/relaxed'], ['relaxed/simple'], ['simple/relaxed'], ['simple/simple']];
    }

    #[DataProvider('canonicalizations')]
    public function testItVerifiesEverySupportedCanonicalization(string $canonicalization): void
    {
        $result = $this->verifyOne($this->sign(self::MESSAGE, $canonicalization));

        self::assertSame(SignatureStatus::Pass, $result->status, $result->reason);
    }

    public function testItVerifiesASignedFixture(): void
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/mime/plain-text.eml');
        self::assertIsString($raw);

        $result = $this->verifyOne($this->sign($raw, 'relaxed/relaxed', [], 'from:to:subject:date:message-id'));

        self::assertSame(SignatureStatus::Pass, $result->status, $result->reason);
    }

    /** A mailer folds a long b= value, and relaxed canonicalization is what makes that survive. */
    public function testItVerifiesASignatureFoldedOverSeveralLines(): void
    {
        $signed = $this->sign(self::MESSAGE);
        $folded = str_replace('; b=', ";\r\n\tb=", $signed);

        self::assertNotSame($signed, $folded);
        self::assertSame(SignatureStatus::Pass, $this->verifyOne($folded)->status);
    }

    public function testOneChangedByteInTheBodyIsABodyHashFailure(): void
    {
        $signed = str_replace('Dit is de body.', 'Dit is de bodz.', $this->sign(self::MESSAGE));
        $result = $this->verifyOne($signed);

        self::assertSame(SignatureStatus::Fail, $result->status);
        self::assertStringContainsString('body hash does not match', $result->reason);
    }

    public function testARemovedSignedHeaderFailsAndSaysWhichOneIsGone(): void
    {
        $signed = str_replace("Subject: Hallo\r\n", '', $this->sign(self::MESSAGE));
        $result = $this->verifyOne($signed);

        self::assertSame(SignatureStatus::Fail, $result->status);
        self::assertStringContainsString('does not verify', $result->reason);
        self::assertStringContainsString('subject', $result->reason);
    }

    public function testAChangedSignedHeaderFails(): void
    {
        $signed = str_replace('Subject: Hallo', 'Subject: Hallo!', $this->sign(self::MESSAGE));

        self::assertSame(SignatureStatus::Fail, $this->verifyOne($signed)->status);
    }

    public function testAnExpiredSignatureFails(): void
    {
        $signed = $this->sign(self::MESSAGE, 'relaxed/relaxed', ['t' => '1700000000', 'x' => '1700003600']);
        $result = $this->verifyOne($signed, 1800000000);

        self::assertSame(SignatureStatus::Fail, $result->status);
        self::assertStringContainsString('expired', $result->reason);
    }

    public function testASignatureStillInsideItsWindowPasses(): void
    {
        $signed = $this->sign(self::MESSAGE, 'relaxed/relaxed', ['t' => '1700000000', 'x' => '1700003600']);

        self::assertSame(SignatureStatus::Pass, $this->verifyOne($signed, 1700001000)->status);
    }

    public function testATimestampInTheFutureIsNoted(): void
    {
        $signed = $this->sign(self::MESSAGE, 'relaxed/relaxed', ['t' => '1900000000']);
        $result = $this->verifyOne($signed, 1700000000);

        self::assertSame(SignatureStatus::Pass, $result->status);
        self::assertStringContainsString('lies in the future', implode(' ', $result->notes));
    }

    public function testTheLengthTagLimitsHowMuchOfTheBodyIsSigned(): void
    {
        $signed = $this->sign(self::MESSAGE, 'relaxed/relaxed', ['l' => '17']) . "Een toevoeging onderaan.\r\n";
        $result = $this->verifyOne($signed);

        self::assertSame(SignatureStatus::Pass, $result->status, $result->reason);
    }

    public function testALengthTagLongerThanTheBodyFails(): void
    {
        $signed = $this->sign(self::MESSAGE, 'relaxed/relaxed', ['l' => '5000']);
        $result = $this->verifyOne($signed);

        self::assertSame(SignatureStatus::Fail, $result->status);
        self::assertStringContainsString('shorter than the signed length', $result->reason);
    }

    public function testAMissingKeyIsReportedSeparatelyFromAFailure(): void
    {
        $result = $this->verifyOne($this->sign(self::MESSAGE), null, new StaticKeyLookup());

        self::assertSame(SignatureStatus::NoKey, $result->status);
        self::assertStringContainsString('No DKIM key is published', $result->reason);
    }

    public function testARevokedKeyIsReportedAsRevoked(): void
    {
        $lookup = new StaticKeyLookup([self::SELECTOR . '._domainkey.' . self::DOMAIN => 'v=DKIM1; k=rsa; p=']);
        $result = $this->verifyOne($this->sign(self::MESSAGE), null, $lookup);

        self::assertSame(SignatureStatus::Revoked, $result->status);
        self::assertStringContainsString('revoked', $result->reason);
    }

    public function testAKeyInTestingModeStillVerifiesButIsNoted(): void
    {
        $lookup = new StaticKeyLookup([self::SELECTOR . '._domainkey.' . self::DOMAIN => 'v=DKIM1; k=rsa; t=y; p=' . self::$publicKey]);
        $result = $this->verifyOne($this->sign(self::MESSAGE), null, $lookup);

        self::assertSame(SignatureStatus::Pass, $result->status, $result->reason);
        self::assertStringContainsString('testing mode', implode(' ', $result->notes));
    }

    public function testAKeyThatDoesNotAllowSha256Fails(): void
    {
        $lookup = new StaticKeyLookup([self::SELECTOR . '._domainkey.' . self::DOMAIN => 'v=DKIM1; k=rsa; h=sha1; p=' . self::$publicKey]);
        $result = $this->verifyOne($this->sign(self::MESSAGE), null, $lookup);

        self::assertSame(SignatureStatus::Fail, $result->status);
        self::assertStringContainsString('does not allow sha256', $result->reason);
    }

    public function testAnUnsupportedKeyTypeIsNotGuessedAt(): void
    {
        $lookup = new StaticKeyLookup([self::SELECTOR . '._domainkey.' . self::DOMAIN => 'v=DKIM1; k=ed25519; p=' . self::$publicKey]);
        $result = $this->verifyOne($this->sign(self::MESSAGE), null, $lookup);

        self::assertSame(SignatureStatus::Unsupported, $result->status);
        self::assertStringContainsString('Key type "ed25519"', $result->reason);
    }

    /** @return list<array{string, string, string}> */
    public static function unsupportedSignatures(): array
    {
        return [
            ['a', 'ed25519-sha256', 'Algorithm "ed25519-sha256"'],
            ['a', 'rsa-sha1', 'Algorithm "rsa-sha1"'],
            ['c', 'relaxed/gzip', 'Canonicalization "relaxed/gzip"'],
            ['v', '2', 'DKIM version "2"'],
            ['q', 'dns/mumble', 'Query method "dns/mumble"'],
        ];
    }

    #[DataProvider('unsupportedSignatures')]
    public function testItSaysUnsupportedRatherThanGuessing(string $tag, string $value, string $expected): void
    {
        $tags = array_merge([
            'v' => '1',
            'a' => 'rsa-sha256',
            'c' => 'relaxed/relaxed',
            'q' => 'dns/txt',
            'd' => self::DOMAIN,
            's' => self::SELECTOR,
            'h' => 'from',
            'bh' => 'AAA=',
            'b' => 'AAA=',
        ], [$tag => $value]);

        $header = 'DKIM-Signature:';

        foreach ($tags as $name => $tagValue) {
            $header .= ' ' . $name . '=' . $tagValue . ';';
        }

        $result = $this->verifyOne($header . "\r\n" . self::MESSAGE);

        self::assertSame(SignatureStatus::Unsupported, $result->status);
        self::assertStringContainsString($expected, $result->reason);
        self::assertSame(self::DOMAIN, $result->domain);
    }

    public function testAMalformedSignatureFailsWithoutTouchingTheKey(): void
    {
        $result = $this->verifyOne("DKIM-Signature: v=1; a=rsa-sha256; d=example.test\r\n" . self::MESSAGE);

        self::assertSame(SignatureStatus::Fail, $result->status);
        self::assertStringContainsString('malformed', $result->reason);
        self::assertSame('', $result->domain);
    }

    public function testASignatureThatDoesNotCoverFromIsInvalid(): void
    {
        $result = $this->verifyOne($this->sign(self::MESSAGE, 'relaxed/relaxed', [], 'to:subject'));

        self::assertSame(SignatureStatus::Fail, $result->status);
        self::assertStringContainsString('From header', $result->reason);
    }

    public function testAMessageWithoutASignatureHasNoResults(): void
    {
        self::assertSame([], $this->verifier()->verify(self::MESSAGE));
    }

    public function testEverySignatureGetsItsOwnResult(): void
    {
        $broken = str_replace('Subject: Hallo', 'Subject: Anders', $this->sign(self::MESSAGE));
        $both = $this->sign($broken);

        $results = $this->verifier()->verify($both);

        self::assertCount(2, $results);
        self::assertSame(SignatureStatus::Pass, $results[0]->status);
        self::assertSame(SignatureStatus::Fail, $results[1]->status);
    }

    public function testItAcceptsBareLineFeeds(): void
    {
        $signed = str_replace("\r\n", "\n", $this->sign(self::MESSAGE));

        self::assertSame(SignatureStatus::Pass, $this->verifyOne($signed)->status);
    }

    public function testTheResultSurvivesAsAnArrayForTheApi(): void
    {
        $result = $this->verifyOne($this->sign(self::MESSAGE));

        self::assertSame('pass', $result->toArray()['status']);
        self::assertSame(self::DOMAIN, $result->toArray()['domain']);
    }

    private function verifyOne(string $raw, ?int $now = null, ?KeyLookup $keys = null): SignatureResult
    {
        $results = ($keys === null ? $this->verifier() : new Verifier($keys))->verify($raw, $now);

        self::assertCount(1, $results);

        return $results[0];
    }

    private function verifier(): Verifier
    {
        return new Verifier(new StaticKeyLookup([
            self::SELECTOR . '._domainkey.' . self::DOMAIN => 'v=DKIM1; k=rsa; p=' . self::$publicKey,
        ]));
    }

    /**
     * Signs a message the way a mailer would. Doing it here rather than from a stored fixture
     * keeps a private key out of the repository and keeps the test from expiring.
     *
     * @param array<string, string> $extraTags
     */
    private function sign(string $message, string $canonicalization = 'relaxed/relaxed', array $extraTags = [], string $signedHeaders = 'from:to:subject'): string
    {
        $message = preg_replace("/\r\n|\r|\n/", "\r\n", $message) ?? $message;
        $boundary = strpos($message, "\r\n\r\n");
        self::assertIsInt($boundary);

        $head = substr($message, 0, $boundary);
        $body = substr($message, $boundary + 4);

        [$headerName, $bodyName] = explode('/', $canonicalization);
        $headerCanon = Canonicalization::from($headerName);
        $canonicalBody = Canonicalization::from($bodyName)->body($body);

        if (isset($extraTags['l'])) {
            $canonicalBody = substr($canonicalBody, 0, (int) $extraTags['l']);
        }

        $tags = array_merge([
            'v' => '1',
            'a' => 'rsa-sha256',
            'c' => $canonicalization,
            'd' => self::DOMAIN,
            's' => self::SELECTOR,
            'h' => $signedHeaders,
            'bh' => base64_encode(hash('sha256', $canonicalBody, true)),
        ], $extraTags);

        $list = '';

        foreach ($tags as $name => $value) {
            $list .= $name . '=' . $value . '; ';
        }

        $data = '';

        foreach (explode(':', $signedHeaders) as $name) {
            foreach (explode("\r\n", $head) as $line) {
                if (stripos($line, $name . ':') === 0) {
                    $data .= $headerCanon->header($line) . "\r\n";
                }
            }
        }

        $data .= $headerCanon->header('DKIM-Signature: ' . $list . 'b=');

        $privateKey = self::$privateKey;
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $privateKey);
        self::assertTrue(openssl_sign($data, $binary, $privateKey, OPENSSL_ALGO_SHA256));
        self::assertIsString($binary);

        return 'DKIM-Signature: ' . $list . 'b=' . base64_encode($binary) . "\r\n" . $message;
    }
}
