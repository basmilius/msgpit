<?php

declare(strict_types=1);

namespace Msgpit\Mail\Dkim;

/**
 * Verifies the DKIM signatures on a raw message (RFC 6376).
 *
 * Deliberately narrow: rsa-sha256 with either canonicalization, which is what every mailer we
 * see actually signs with. Anything else reports "unsupported" with the reason, because a
 * guessed verdict on a signature we cannot check is worse than no verdict.
 *
 * The public key comes from DNS and this class does no DNS: the caller supplies a KeyLookup.
 */
final readonly class Verifier
{
    private const ALGORITHM = 'rsa-sha256';

    public function __construct(private KeyLookup $keys) {}

    /** @return list<SignatureResult> One per DKIM-Signature header, in the order they appear. */
    public function verify(string $raw, ?int $now = null): array
    {
        $raw = preg_replace("/\r\n|\r|\n/", "\r\n", $raw) ?? $raw;
        $boundary = strpos($raw, "\r\n\r\n");

        $head = $boundary === false ? $raw : substr($raw, 0, $boundary);
        $body = $boundary === false ? '' : substr($raw, $boundary + 4);

        $headers = Header::all($head);
        $results = [];

        foreach ($headers as $header) {
            if ($header->name === 'dkim-signature') {
                $results[] = $this->check($header, $headers, $body, $now ?? time());
            }
        }

        return $results;
    }

    /** @param list<Header> $headers */
    private function check(Header $header, array $headers, string $body, int $now): SignatureResult
    {
        $signature = Signature::parse($header->raw);

        if ($signature === null) {
            return new SignatureResult('', '', '', SignatureStatus::Fail, 'The DKIM-Signature header is malformed or misses a required tag.');
        }

        $unsupported = $this->unsupported($signature);

        if ($unsupported !== null) {
            return SignatureResult::of($signature, SignatureStatus::Unsupported, $unsupported);
        }

        $headerCanon = $signature->headerCanonicalization();
        $bodyCanon = $signature->bodyCanonicalization();

        if ($headerCanon === null || $bodyCanon === null) {
            return SignatureResult::of($signature, SignatureStatus::Unsupported, sprintf('Canonicalization "%s" is not supported; use simple or relaxed for both halves.', $signature->canonicalization));
        }

        if (!in_array('from', $signature->signedHeaders, true)) {
            return SignatureResult::of($signature, SignatureStatus::Fail, 'The h= tag does not cover the From header, which RFC 6376 requires.');
        }

        $notes = [];

        if ($signature->timestamp !== null && $signature->timestamp > $now) {
            $notes[] = sprintf('The signature timestamp t=%d lies in the future.', $signature->timestamp);
        }

        if ($signature->expiration !== null && $signature->expiration < $now) {
            return SignatureResult::of($signature, SignatureStatus::Fail, sprintf('The signature expired on %s (x=%d).', gmdate('Y-m-d H:i:s', $signature->expiration) . ' UTC', $signature->expiration), $notes);
        }

        $bodyFailure = $this->bodyHashFailure($signature, $bodyCanon->body($body));

        if ($bodyFailure !== null) {
            return SignatureResult::of($signature, SignatureStatus::Fail, $bodyFailure, $notes);
        }

        return $this->checkSignature($signature, $headers, $headerCanon, $notes);
    }

    private function unsupported(Signature $signature): ?string
    {
        if ($signature->version !== '1') {
            return sprintf('DKIM version "%s" is not supported; only v=1 exists.', $signature->version);
        }

        if ($signature->algorithm !== self::ALGORITHM) {
            return sprintf('Algorithm "%s" is not supported; msgpit verifies %s only.', $signature->algorithm, self::ALGORITHM);
        }

        if (!$signature->usesDnsQuery()) {
            return sprintf('Query method "%s" is not supported; only dns/txt is.', $signature->query);
        }

        if (!extension_loaded('openssl')) {
            return 'The openssl extension is not loaded, so signatures cannot be verified.';
        }

        return null;
    }

    /**
     * The body hash is checked on its own because it is the one that usually breaks: exporting a
     * message to .eml, or any hop that rewrites the body, changes it while the headers survive.
     */
    private function bodyHashFailure(Signature $signature, string $canonicalBody): ?string
    {
        if ($signature->bodyLength !== null) {
            if ($signature->bodyLength > strlen($canonicalBody)) {
                return sprintf('The body is shorter than the signed length l=%d, so part of what was signed is gone.', $signature->bodyLength);
            }

            $canonicalBody = substr($canonicalBody, 0, $signature->bodyLength);
        }

        $expected = base64_decode($signature->bodyHash, true);

        if ($expected === false) {
            return 'The bh= tag is not valid base64.';
        }

        if (!hash_equals(hash('sha256', $canonicalBody, true), $expected)) {
            return 'The body hash does not match bh=, so the body changed after it was signed.';
        }

        return null;
    }

    /**
     * @param list<Header> $headers
     * @param list<string> $notes
     */
    private function checkSignature(Signature $signature, array $headers, Canonicalization $canon, array $notes): SignatureResult
    {
        $record = $this->keys->publicKey($signature->selector, $signature->domain);
        $location = sprintf('%s._domainkey.%s', $signature->selector, $signature->domain);

        if ($record === null) {
            return SignatureResult::of($signature, SignatureStatus::NoKey, sprintf('No DKIM key is published at %s.', $location), $notes);
        }

        $key = PublicKeyRecord::parse($record);

        if ($key === null) {
            return SignatureResult::of($signature, SignatureStatus::Fail, sprintf('The key record at %s is malformed.', $location), $notes);
        }

        if ($key->revoked()) {
            return SignatureResult::of($signature, SignatureStatus::Revoked, sprintf('The key at %s is revoked: p= is empty.', $location), $notes);
        }

        if ($key->keyType !== 'rsa') {
            return SignatureResult::of($signature, SignatureStatus::Unsupported, sprintf('Key type "%s" at %s is not supported; msgpit verifies rsa only.', $key->keyType, $location), $notes);
        }

        if (!$key->allows('sha256')) {
            return SignatureResult::of($signature, SignatureStatus::Fail, sprintf('The key at %s does not allow sha256.', $location), $notes);
        }

        if ($key->testing) {
            $notes[] = 'The key is in testing mode (t=y), so the domain does not want failures acted on.';
        }

        $publicKey = $key->openSslKey();
        $bytes = base64_decode($signature->signature, true);

        if ($publicKey === null) {
            return SignatureResult::of($signature, SignatureStatus::Fail, sprintf('The p= value at %s is not a usable RSA public key.', $location), $notes);
        }

        if ($bytes === false) {
            return SignatureResult::of($signature, SignatureStatus::Fail, 'The b= tag is not valid base64.', $notes);
        }

        $verified = openssl_verify($this->signedData($signature, $headers, $canon), $bytes, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verified === 1) {
            return SignatureResult::of($signature, SignatureStatus::Pass, sprintf('Signed by %s and verified against the key at %s.', $signature->domain, $location), $notes);
        }

        $missing = $this->missingHeaders($signature, $headers);
        $detail = $missing === [] ? '' : sprintf(' Headers listed in h= but absent from the message: %s.', implode(', ', $missing));

        return SignatureResult::of($signature, SignatureStatus::Fail, sprintf('The signature does not verify against the key at %s.%s', $location, $detail), $notes);
    }

    /**
     * The hashed header block: every name in h= in that order, then the signature itself with an
     * empty b= and no trailing CRLF. A name repeated in h= takes the next instance from the
     * bottom up (RFC 6376 5.4.2), which is how a signer protects against a header being added.
     *
     * @param list<Header> $headers
     */
    private function signedData(Signature $signature, array $headers, Canonicalization $canon): string
    {
        $data = '';
        $taken = [];

        foreach ($signature->signedHeaders as $name) {
            $instances = array_values(array_filter($headers, static fn (Header $h): bool => $h->name === $name));
            $index = count($instances) - 1 - ($taken[$name] ?? 0);
            $taken[$name] = ($taken[$name] ?? 0) + 1;

            if ($index >= 0) {
                $data .= $canon->header($instances[$index]->raw) . "\r\n";
            }
        }

        return $data . $canon->header($signature->headerWithoutSignature());
    }

    /**
     * @param list<Header> $headers
     * @return list<string>
     */
    private function missingHeaders(Signature $signature, array $headers): array
    {
        $present = array_map(static fn (Header $h): string => $h->name, $headers);

        return array_values(array_unique(array_filter(
            $signature->signedHeaders,
            static fn (string $name): bool => !in_array($name, $present, true),
        )));
    }
}
