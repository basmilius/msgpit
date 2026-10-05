<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Core\DnsKeyLookup;
use Msgpit\Mail\Dkim\SignatureResult;
use Msgpit\Mail\Dkim\SignatureStatus;
use Msgpit\Mail\Dkim\Verifier;
use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * Verifying the signature ourselves, which is the fallback and not the answer.
 *
 * When the receiving server already wrote a verdict into Authentication-Results, that is what the
 * report shows: it had the sending IP in front of it and the key as it was then. This is for the
 * case it leaves open, a signature with no verdict attached, which is what a .eml out of a Sent
 * folder looks like. It answers "does my sending setup sign correctly", not "was this accepted".
 *
 * A failing body hash is its own outcome rather than a bad signature, because that is what happens
 * when a mail client re-encodes a message on export: one byte moves and the hash is gone.
 */
final readonly class DkimSignature implements Check
{
    public function run(Context $context): Finding
    {
        $dns = $context->dns;

        if ($dns === null || !$dns->enabled()) {
            return Finding::skip('dkim', Section::Authentication, 'DKIM signature', 'DNS lookups are switched off.');
        }

        if ($context->header('dkim-signature') === null) {
            return Finding::warn(
                'dkim',
                Section::Authentication,
                'The message carries no DKIM signature',
                1.0,
                'Without one, DMARC has only SPF to lean on, and SPF does not survive forwarding.',
            );
        }

        if ($context->header('authentication-results') !== null) {
            return Finding::skip(
                'dkim',
                Section::Authentication,
                'DKIM signature, verified here',
                'The receiving server already judged this signature, and it had the key as it was at the time.',
            );
        }

        $results = (new Verifier(new DnsKeyLookup($dns)))->verify($context->mail->raw);

        if ($results === []) {
            return Finding::warn('dkim', Section::Authentication, 'The signature could not be read', 1.0);
        }

        $evidence = array_map(
            static fn (SignatureResult $r): string => sprintf('%s (%s, %s): %s %s', $r->domain, $r->selector, $r->algorithm, $r->status->value, $r->reason),
            $results,
        );

        foreach ($results as $result) {
            if ($result->status === SignatureStatus::Pass) {
                return Finding::pass('dkim', Section::Authentication, "The signature from {$result->domain} verifies", '', $evidence);
            }
        }

        $first = $results[0];

        if (in_array($first->status, [SignatureStatus::Unsupported, SignatureStatus::NoKey], true)) {
            return Finding::skip('dkim', Section::Authentication, 'DKIM signature', $first->reason);
        }

        return Finding::fail(
            'dkim',
            Section::Authentication,
            "The signature from {$first->domain} does not verify",
            1.5,
            'An exported .eml is often no longer byte-identical to what was signed, so check that before the key.',
            $evidence,
        );
    }
}
