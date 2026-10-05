<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * A sending address should name itself, and that name should point back. Forward-confirmed reverse
 * DNS is one of the oldest things a receiving server checks, and a sender without it is treated as
 * a machine nobody stands behind.
 */
final readonly class ReverseDns implements Check
{
    public function run(Context $context): Finding
    {
        $dns = $context->dns;
        $ip = $context->origin->ip;

        if ($dns === null || !$dns->enabled()) {
            return Finding::skip('reverse-dns', Section::Authentication, 'Reverse DNS', 'DNS lookups are switched off.');
        }

        if ($ip === null) {
            return Finding::skip(
                'reverse-dns',
                Section::Authentication,
                'Reverse DNS',
                'This message names no sending address, because it never crossed a network.',
            );
        }

        $name = $dns->reverse($ip);

        if ($name === null) {
            return Finding::fail(
                'reverse-dns',
                Section::Authentication,
                "The sending address {$ip} has no reverse DNS",
                1.0,
                'Receiving servers hold this against a sender before reading anything else.',
            );
        }

        $forward = $dns->addresses($name);
        $evidence = ["{$ip} resolves to {$name}", "{$name} resolves to " . (implode(', ', $forward) ?: 'nothing')];

        if (!in_array($ip, $forward, true)) {
            return Finding::warn(
                'reverse-dns',
                Section::Authentication,
                "The reverse name {$name} does not point back",
                0.5,
                'Forward-confirmed reverse DNS needs the name to resolve to the address again.',
                $evidence,
            );
        }

        return Finding::pass('reverse-dns', Section::Authentication, "The sending address is {$name}, confirmed both ways", '', $evidence);
    }
}
