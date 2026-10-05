<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * DMARC passes on alignment, not merely on SPF or DKIM passing: the domain that authenticated has
 * to be the domain the reader sees in From. A bounce address on another domain is the usual way
 * that quietly stops being true.
 */
final readonly class ReturnPath implements Check
{
    public function run(Context $context): Finding
    {
        $from = Context::domainOf($context->header('from'));
        $returnPath = Context::domainOf($context->header('return-path'));

        if ($from === null || $returnPath === null) {
            return Finding::skip(
                'return-path',
                Section::Headers,
                'Return-Path alignment',
                'This message has no Return-Path, which is added by the sending server.',
            );
        }

        $evidence = ["From: {$from}", "Return-Path: {$returnPath}"];

        if ($returnPath === $from || str_ends_with($returnPath, ".{$from}") || str_ends_with($from, ".{$returnPath}")) {
            return Finding::pass('return-path', Section::Headers, 'The bounce address is on the sender domain', '', $evidence);
        }

        return Finding::warn(
            'return-path',
            Section::Headers,
            'The bounce address is on another domain than the sender',
            1.0,
            'DMARC needs the authenticated domain to line up with the one the reader sees.',
            $evidence,
        );
    }
}
