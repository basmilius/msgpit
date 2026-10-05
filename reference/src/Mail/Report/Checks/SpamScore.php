<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * The heaviest single item, as it is everywhere else: a message SpamAssassin dislikes is not
 * saved by anything further down the list. The score is subtracted directly, capped so one
 * spectacular rule cannot swallow the entire report.
 */
final readonly class SpamScore implements Check
{
    private const MAX_PENALTY = 5.0;

    public function run(Context $context): Finding
    {
        $spam = $context->spam;

        if ($spam === null) {
            return Finding::skip(
                'spam-score',
                Section::Spam,
                'SpamAssassin score',
                'No spamd was reachable when this message came in, so it has no score.',
            );
        }

        $evidence = array_map(
            static fn (array $rule): string => sprintf('%+.1f %s: %s', $rule['points'], $rule['name'], $rule['description']),
            $spam->rules,
        );
        $headline = sprintf('%.1f of %.1f', $spam->score, $spam->threshold);

        if ($spam->score >= $spam->threshold) {
            return Finding::fail(
                'spam-score',
                Section::Spam,
                "SpamAssassin scores this message {$headline}",
                min($spam->score, self::MAX_PENALTY),
                'Over the threshold: a filter with these rules would treat it as spam.',
                $evidence,
            );
        }

        if ($spam->score > 0) {
            return Finding::warn(
                'spam-score',
                Section::Spam,
                "SpamAssassin scores this message {$headline}",
                min($spam->score, self::MAX_PENALTY),
                'Under the threshold, but every point is a rule you could do without.',
                $evidence,
            );
        }

        return Finding::pass(
            'spam-score',
            Section::Spam,
            "SpamAssassin scores this message {$headline}",
            'Nothing in the content works against it.',
            $evidence,
        );
    }
}
