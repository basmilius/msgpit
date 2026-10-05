<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

use Msgpit\Core\Resolver;

/**
 * Asking the well known DNS blocklists whether they know a sending address.
 *
 * A blocklist is queried by turning the address around under the list's own zone and asking for an
 * A record: an answer means listed, no answer means not. The answer is never just yes, though. The
 * address is encoded in the last octet, and the same 127.0.0.x that means "spam source" on one list
 * means "known good" on another, so a result is only worth reporting once it has been read against
 * the list that gave it.
 *
 * IPv4 only. The IPv6 zones are a different, nibble-reversed format that few of these lists answer
 * on at all, and guessing there would be worse than saying so.
 */
final readonly class Blocklists
{
    /** @var array<string, string> zone => the name people know it by */
    private const ZONES = [
        'zen.spamhaus.org' => 'Spamhaus',
        'b.barracudacentral.org' => 'Barracuda',
        'bl.spamcop.net' => 'SpamCop',
        'psbl.surriel.com' => 'PSBL',
        'db.wpbl.info' => 'WPBL',
        'bl.mailspike.net' => 'Mailspike',
        'hostkarma.junkemailfilter.com' => 'Hostkarma',
        'dnsbl-1.uceprotect.net' => 'UCEPROTECT',
        'truncate.gbudb.net' => 'GBUdb Truncate',
        'bl.blocklist.de' => 'blocklist.de',
        'all.s5h.net' => 's5h',
        'dnsbl.dronebl.org' => 'DroneBL',
        'spam.dnsbl.anonmails.de' => 'Anonmails',
        'ips.backscatterer.org' => 'Backscatterer',
        'rbl.interserver.net' => 'InterServer',
        'spamrbl.imp.ch' => 'IMP',
    ];

    public function __construct(private Resolver $dns) {}

    public static function count(): int
    {
        return count(self::ZONES);
    }

    /** @return list<BlocklistResult> */
    public function check(string $ip): array
    {
        $reversed = self::reverse($ip);

        if ($reversed === null) {
            return [];
        }

        $results = [];

        foreach (self::ZONES as $zone => $name) {
            $codes = $this->dns->addresses("{$reversed}.{$zone}");
            $results[] = new BlocklistResult($name, $zone, $codes, self::verdict($zone, $codes));
        }

        return $results;
    }

    private static function reverse(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return implode('.', array_reverse(explode('.', $ip)));
    }

    /** @param list<string> $codes */
    private static function verdict(string $zone, array $codes): BlocklistVerdict
    {
        if ($codes === []) {
            return BlocklistVerdict::Clean;
        }

        foreach ($codes as $code) {
            // Every list uses this range to say it will not answer, which happens when the query
            // comes through a public resolver. Reading it as "listed" would be exactly backwards.
            if (str_starts_with($code, '127.255.255.')) {
                return BlocklistVerdict::Refused;
            }
        }

        $last = (int) (explode('.', $codes[0])[3] ?? 0);

        return match ($zone) {
            // 1 is a whitelist, 3 is "seen but not enough to act on", 5 says do not block.
            'hostkarma.junkemailfilter.com' => match ($last) {
                1, 5 => BlocklistVerdict::Good,
                3 => BlocklistVerdict::Caution,
                default => BlocklistVerdict::Listed,
            },
            // Mailspike splits its range: the high codes are reputations, not accusations.
            'bl.mailspike.net' => $last >= 17 ? BlocklistVerdict::Good : ($last >= 11 ? BlocklistVerdict::Caution : BlocklistVerdict::Listed),
            // The policy block list is "this address should not be sending mail directly", which is
            // true of every home connection and says nothing about the message.
            'zen.spamhaus.org' => in_array($last, [10, 11], true) ? BlocklistVerdict::Caution : BlocklistVerdict::Listed,
            default => BlocklistVerdict::Listed,
        };
    }
}
