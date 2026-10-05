<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Dmarc;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * The sender's own DMARC record, read from DNS. This is about the domain, not about this message,
 * so it says something even for a mail that never left the machine: whether the domain you send
 * from has told the world what to do with post that fails its checks.
 */
final readonly class DmarcPolicy implements Check
{
    public function run(Context $context): Finding
    {
        $dns = $context->dns;
        $domain = $context->fromDomain();

        if ($dns === null || !$dns->enabled()) {
            return Finding::skip('dmarc', Section::Authentication, 'DMARC policy', 'DNS lookups are switched off.');
        }

        if ($domain === null) {
            return Finding::skip('dmarc', Section::Authentication, 'DMARC policy', 'The message has no sender domain to look up.');
        }

        $dmarc = Dmarc::lookup($dns, $domain);

        if ($dmarc === null) {
            return Finding::fail(
                'dmarc',
                Section::Authentication,
                "{$domain} publishes no DMARC record",
                1.5,
                'Without one, nothing stops another server from sending as this domain, and Gmail now requires it from bulk senders.',
                ["No v=DMARC1 record at _dmarc.{$domain} or above it"],
            );
        }

        $evidence = ["_dmarc.{$dmarc->domain}: {$dmarc->record}"];

        if ($dmarc->percentage < 100) {
            $evidence[] = "Applied to {$dmarc->percentage}% of failing mail";
        }

        if ($dmarc->policy === 'none') {
            return Finding::warn(
                'dmarc',
                Section::Authentication,
                "{$domain} publishes DMARC, but the policy is p=none",
                0.5,
                'A record that asks for reports and no action. Fine while you are measuring, not a defence.',
                $evidence,
            );
        }

        return Finding::pass(
            'dmarc',
            Section::Authentication,
            "{$domain} publishes DMARC with p={$dmarc->policy}",
            '',
            $evidence,
        );
    }
}
