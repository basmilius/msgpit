<?php

declare(strict_types=1);

namespace Msgpit\Core;

use Msgpit\Mail\Dkim\KeyLookup;

/** The DKIM verifier does no DNS of its own, so this is where the two meet. */
final readonly class DnsKeyLookup implements KeyLookup
{
    public function __construct(private Resolver $dns) {}

    public function publicKey(string $selector, string $domain): ?string
    {
        // A key record can be split over several strings; DNS hands them back joined per record,
        // and several records at one name means a zone we cannot make sense of anyway.
        $records = $this->dns->txt("{$selector}._domainkey.{$domain}");

        foreach ($records as $record) {
            if (str_contains($record, 'p=')) {
                return $record;
            }
        }

        return $records[0] ?? null;
    }
}
