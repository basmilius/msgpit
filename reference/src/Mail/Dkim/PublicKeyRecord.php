<?php

declare(strict_types=1);

namespace Msgpit\Mail\Dkim;

use OpenSSLAsymmetricKey;

/** The TXT record at <selector>._domainkey.<domain>, as described in RFC 6376 3.6.1. */
final readonly class PublicKeyRecord
{
    /** @param list<string> $hashAlgorithms Empty means the key allows every hash. */
    private function __construct(
        public string $keyType,
        public string $publicKey,
        public array $hashAlgorithms,
        public bool $testing,
    ) {}

    public static function parse(string $record): ?self
    {
        $tags = TagList::parse($record);

        if ($tags === null || !isset($tags['p'])) {
            return null;
        }

        if (isset($tags['v']) && strtoupper($tags['v']) !== 'DKIM1') {
            return null;
        }

        $hashes = array_values(array_filter(explode(':', strtolower(TagList::compact($tags['h'] ?? '')))));
        $flags = explode(':', strtolower(TagList::compact($tags['t'] ?? '')));

        return new self(
            keyType: strtolower($tags['k'] ?? 'rsa'),
            publicKey: TagList::compact($tags['p']),
            hashAlgorithms: $hashes,
            testing: in_array('y', $flags, true),
        );
    }

    /** An empty p= is how a domain withdraws a key without withdrawing the record. */
    public function revoked(): bool
    {
        return $this->publicKey === '';
    }

    public function allows(string $hash): bool
    {
        return $this->hashAlgorithms === [] || in_array($hash, $this->hashAlgorithms, true);
    }

    public function openSslKey(): ?OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($this->publicKey, 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $key = @openssl_pkey_get_public($pem);

        return $key === false ? null : $key;
    }
}
