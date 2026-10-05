<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Core\Resolver;
use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;
use Msgpit\Mail\Spf\Evaluator;
use Msgpit\Mail\Spf\SpfStatus;

/**
 * Was the machine that sent this allowed to send for this domain?
 *
 * Two answers, depending on what the message can tell us. A delivered .eml names the sending
 * address in its Received headers, so the record can be evaluated against it for a real verdict.
 * A mail we caught ourselves has no address, but the domain still has a record or it does not,
 * and that is worth reporting on its own: it is the half of the setup you control from here.
 */
final readonly class Spf implements Check
{
    public function run(Context $context): Finding
    {
        $dns = $context->dns;
        $domain = $context->fromDomain();

        if ($dns === null || !$dns->enabled()) {
            return Finding::skip('spf', Section::Authentication, 'SPF', 'DNS lookups are switched off.');
        }

        if ($domain === null) {
            return Finding::skip('spf', Section::Authentication, 'SPF', 'The message has no sender domain to look up.');
        }

        $ip = $context->origin->ip;

        if ($ip === null) {
            return $this->recordOnly($dns, $domain);
        }

        $result = (new Evaluator($dns))->evaluate($ip, $domain);
        $evidence = array_values(array_filter([
            "Sending address: {$ip}",
            $result->record !== null ? "{$domain}: {$result->record}" : null,
            $result->mechanism !== null ? "Decided by: {$result->mechanism}" : null,
            ...array_map(static fn (string $note): string => "Note: {$note}", $result->notes),
        ]));

        return match ($result->status) {
            SpfStatus::Pass => Finding::pass('spf', Section::Authentication, "SPF passes for {$ip}", '', $evidence),
            SpfStatus::Fail => Finding::fail('spf', Section::Authentication, "SPF fails for {$ip}", 2.0, $result->reason, $evidence),
            SpfStatus::SoftFail => Finding::warn('spf', Section::Authentication, "SPF softfails for {$ip}", 1.0, $result->reason, $evidence),
            SpfStatus::None => Finding::fail('spf', Section::Authentication, "{$domain} publishes no SPF record", 1.5, $result->reason, $evidence),
            SpfStatus::Neutral => Finding::warn('spf', Section::Authentication, "SPF is neutral about {$ip}", 0.5, $result->reason, $evidence),
            // A record we cannot evaluate is a record no receiving server can evaluate either.
            SpfStatus::PermError => Finding::fail('spf', Section::Authentication, "The SPF record of {$domain} cannot be evaluated", 1.5, $result->reason, $evidence),
            SpfStatus::TempError => Finding::skip('spf', Section::Authentication, 'SPF', $result->reason),
        };
    }

    private function recordOnly(Resolver $dns, string $domain): Finding
    {
        foreach ($dns->txt($domain) as $record) {
            if (stripos(ltrim($record), 'v=spf1') === 0) {
                $ends = str_contains($record, '-all') ? 'ends in -all, which asks servers to reject anything else' : null;

                return Finding::pass(
                    'spf',
                    Section::Authentication,
                    "{$domain} publishes an SPF record",
                    'This message named no sending address, so the record is reported rather than evaluated.'
                        . ($ends === null ? '' : " It {$ends}."),
                    ["{$domain}: {$record}"],
                );
            }
        }

        return Finding::fail(
            'spf',
            Section::Authentication,
            "{$domain} publishes no SPF record",
            1.5,
            'Any server may then claim to send for this domain, and DMARC has only DKIM left to lean on.',
        );
    }
}
