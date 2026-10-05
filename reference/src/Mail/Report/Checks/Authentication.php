<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * What the receiving server concluded about SPF, DKIM and DMARC, read from the headers it added.
 *
 * Reported, not recomputed. The server that wrote this had the sending IP in front of it and the
 * key as it was at that moment; we have a file. Verifying it again here would fail on a message
 * that was accepted, because an exported .eml is rarely byte-identical to what was signed and the
 * selector may since have been rotated.
 *
 * A message we caught ourselves has none of this, because it never left the machine.
 */
final readonly class Authentication implements Check
{
    public function run(Context $context): Finding
    {
        $results = $context->header('authentication-results');
        $spf = $context->header('received-spf');
        $signed = $context->header('dkim-signature') !== null;

        if ($results === null && $spf === null && !$signed) {
            return Finding::skip(
                'authentication',
                Section::Authentication,
                'No receiving server judged this message',
                'It never left the machine. Import a delivered .eml to see what a real server made of it.',
            );
        }

        $evidence = array_values(array_filter([
            $results !== null ? "Authentication-Results: {$results}" : null,
            $spf !== null ? "Received-SPF: {$spf}" : null,
            $signed ? 'DKIM-Signature: present' : null,
        ]));

        $verdicts = [];

        foreach (['spf', 'dkim', 'dmarc'] as $mechanism) {
            if ($results !== null && preg_match('/\b' . $mechanism . '=(\w+)/i', $results, $match) === 1) {
                $verdicts[$mechanism] = strtolower($match[1]);
            }
        }

        if ($verdicts === [] && $spf !== null && preg_match('/^(\w+)/', $spf, $match) === 1) {
            $verdicts['spf'] = strtolower($match[1]);
        }

        $failed = array_keys(array_filter($verdicts, static fn (string $v): bool => in_array($v, ['fail', 'softfail', 'permerror', 'temperror'], true)));
        $passed = array_keys(array_filter($verdicts, static fn (string $v): bool => $v === 'pass'));
        $summary = implode(', ', array_map(
            static fn (string $mechanism, string $verdict): string => strtoupper($mechanism) . ' ' . $verdict,
            array_keys($verdicts),
            array_values($verdicts),
        ));

        if ($failed !== []) {
            return Finding::fail(
                'authentication',
                Section::Authentication,
                "The receiving server recorded {$summary}",
                2.0,
                'A failing mechanism is what sends a message to the spam folder before its content is even weighed.',
                $evidence,
            );
        }

        if ($passed === []) {
            return Finding::warn(
                'authentication',
                Section::Authentication,
                'The receiving server recorded no verdict',
                1.0,
                'There is a signature or an SPF result, but nothing that says it was accepted.',
                $evidence,
            );
        }

        return Finding::pass(
            'authentication',
            Section::Authentication,
            "The receiving server recorded {$summary}",
            'The sending setup identified itself and the server believed it.',
            $evidence,
        );
    }
}
