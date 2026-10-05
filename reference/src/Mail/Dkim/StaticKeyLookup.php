<?php

declare(strict_types=1);

namespace Msgpit\Mail\Dkim;

/**
 * A lookup that looks nothing up: it answers from records it was handed. The real one resolves
 * TXT records and lives outside this namespace, so verification stays testable without DNS.
 */
final readonly class StaticKeyLookup implements KeyLookup
{
    /** @var array<string, string> */
    private array $records;

    /** @param array<string, string> $records Keyed by "<selector>._domainkey.<domain>". */
    public function __construct(array $records = [])
    {
        $this->records = array_change_key_case($records, CASE_LOWER);
    }

    public function publicKey(string $selector, string $domain): ?string
    {
        return $this->records[strtolower($selector . '._domainkey.' . rtrim($domain, '.'))] ?? null;
    }
}
