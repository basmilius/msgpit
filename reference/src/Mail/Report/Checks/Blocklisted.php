<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\BlocklistResult;
use Msgpit\Mail\Report\Blocklists;
use Msgpit\Mail\Report\BlocklistVerdict;
use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * Whether the sending address is known to the blocklists receiving servers consult. This is about
 * the machine that sent the message, not about the message, which is why it only means anything
 * for a mail that was actually delivered from somewhere.
 */
final readonly class Blocklisted implements Check
{
    public function run(Context $context): Finding
    {
        $dns = $context->dns;
        $ip = $context->origin->ip;

        if ($dns === null || !$dns->enabled()) {
            return Finding::skip('blocklists', Section::Reputation, 'Blocklists', 'DNS lookups are switched off.');
        }

        if ($ip === null) {
            return Finding::skip(
                'blocklists',
                Section::Reputation,
                'Blocklists',
                'This message names no sending address, because it never crossed a network.',
            );
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return Finding::skip(
                'blocklists',
                Section::Reputation,
                'Blocklists',
                "The sending address {$ip} is IPv6, and these lists answer on IPv4 only.",
            );
        }

        $results = (new Blocklists($dns))->check($ip);
        $of = static fn (BlocklistVerdict $verdict): array => array_values(array_filter(
            $results,
            static fn (BlocklistResult $r): bool => $r->verdict === $verdict,
        ));

        $listed = $of(BlocklistVerdict::Listed);
        $caution = $of(BlocklistVerdict::Caution);
        $refused = $of(BlocklistVerdict::Refused);

        // Worst first: a clean list of sixteen lines buries the one that matters.
        usort($results, static fn (BlocklistResult $a, BlocklistResult $b): int => self::weight($b) <=> self::weight($a));
        $evidence = array_map(static fn (BlocklistResult $r): string => $r->describe(), $results);
        $checked = count($results) - count($refused);

        if ($listed !== []) {
            $names = implode(', ', array_map(static fn (BlocklistResult $r): string => $r->name, $listed));

            return Finding::fail(
                'blocklists',
                Section::Reputation,
                'The sending address is listed in ' . $names,
                min(3.0, 1.5 * count($listed)),
                'Servers that consult these lists refuse the message before reading it.',
                $evidence,
            );
        }

        if ($caution !== []) {
            return Finding::warn(
                'blocklists',
                Section::Reputation,
                'The sending address is on a policy list',
                0.5,
                'Not an accusation: these lists mark addresses that should not be sending mail directly, such as home connections.',
                $evidence,
            );
        }

        if ($checked === 0) {
            return Finding::skip(
                'blocklists',
                Section::Reputation,
                'Blocklists',
                'Every list declined to answer. They do that for queries through a public resolver, so this needs a resolver of your own.',
            );
        }

        return Finding::pass(
            'blocklists',
            Section::Reputation,
            "The sending address is on none of the {$checked} blocklists checked",
            count($refused) > 0 ? count($refused) . ' of them declined to answer.' : '',
            $evidence,
        );
    }

    private static function weight(BlocklistResult $result): int
    {
        return match ($result->verdict) {
            BlocklistVerdict::Listed => 4,
            BlocklistVerdict::Caution => 3,
            BlocklistVerdict::Refused => 2,
            BlocklistVerdict::Good => 1,
            BlocklistVerdict::Clean => 0,
        };
    }
}
