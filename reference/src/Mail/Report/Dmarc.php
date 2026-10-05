<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

use Msgpit\Core\Resolver;

/**
 * A DMARC record and where it was found.
 *
 * RFC 7489 says to fall back from the exact domain to the organisational domain, and to determine
 * that with the public suffix list. We walk up one label at a time instead, stopping while two
 * labels are left. It reaches the same record for every real zone, costs a lookup or two more, and
 * saves carrying a 200 kB list that has to be kept fresh. The case it would get wrong is a public
 * suffix that publishes DMARC of its own, and none do.
 */
final readonly class Dmarc
{
    private function __construct(
        public string $domain,
        public string $record,
        public string $policy,
        public string $subdomainPolicy,
        public string $alignmentDkim,
        public string $alignmentSpf,
        public int $percentage,
    ) {}

    public static function lookup(Resolver $dns, string $domain): ?self
    {
        foreach (self::candidates($domain) as $candidate) {
            foreach ($dns->txt("_dmarc.{$candidate}") as $record) {
                if (stripos(ltrim($record), 'v=DMARC1') !== 0) {
                    continue;
                }

                return self::parse($candidate, $record);
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function candidates(string $domain): array
    {
        $labels = explode('.', trim($domain, '.'));
        $candidates = [];

        for ($i = 0; count($labels) - $i >= 2; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }

        return $candidates;
    }

    private static function parse(string $domain, string $record): self
    {
        $tags = [];

        foreach (explode(';', $record) as $pair) {
            if (str_contains($pair, '=')) {
                [$name, $value] = explode('=', $pair, 2);
                $tags[strtolower(trim($name))] = strtolower(trim($value));
            }
        }

        $policy = $tags['p'] ?? 'none';
        $percentage = isset($tags['pct']) && is_numeric($tags['pct']) ? (int) $tags['pct'] : 100;

        return new self(
            domain: $domain,
            record: trim($record),
            policy: $policy,
            // Without sp= a subdomain inherits the main policy, which is what makes p=none carry.
            subdomainPolicy: $tags['sp'] ?? $policy,
            alignmentDkim: $tags['adkim'] ?? 'r',
            alignmentSpf: $tags['aspf'] ?? 'r',
            percentage: max(0, min(100, $percentage)),
        );
    }

    /** Whether an authenticated domain lines up with the one the reader sees. */
    public function aligns(string $authenticated, string $from, string $mode): bool
    {
        $authenticated = strtolower(trim($authenticated, '.'));
        $from = strtolower(trim($from, '.'));

        if ($authenticated === $from) {
            return true;
        }

        // Strict alignment demands the same domain; relaxed accepts a shared organisational one.
        return $mode !== 's'
            && (str_ends_with($authenticated, ".{$from}") || str_ends_with($from, ".{$authenticated}"));
    }
}
