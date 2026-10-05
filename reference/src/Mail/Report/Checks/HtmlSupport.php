<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;
use Msgpit\Mime\HtmlCheck;
use Msgpit\Mime\HtmlCheckFinding;

/** The caniemail verdict, folded in so one number covers the whole message. */
final readonly class HtmlSupport implements Check
{
    public function run(Context $context): Finding
    {
        $result = $context->html === null ? null : HtmlCheck::analyse($context->html);

        if ($result === null || $result->tested() === 0) {
            return Finding::skip('html-support', Section::Content, 'Client support', 'The message has no html to test.');
        }

        $supported = $result->percentage();
        $title = "{$supported}% of what this message uses is supported outright";
        $evidence = array_map(
            static fn (HtmlCheckFinding $finding): string => sprintf('%s: %.0f%% supported', $finding->title, $finding->score() * 100),
            array_slice($result->warnings(), 0, 8),
        );

        if ($supported >= 90.0) {
            return Finding::pass('html-support', Section::Content, $title, '', $evidence);
        }

        // Scaled rather than a cliff: mail html is a series of compromises, and the number only
        // starts to mean something once a real part of the message is at risk.
        $penalty = round(min(1.0, (90.0 - $supported) / 40), 1);

        return Finding::warn(
            'html-support',
            Section::Content,
            $title,
            $penalty,
            'Some of what this message uses falls back or fails in common clients.',
            $evidence,
        );
    }
}
