<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * Gmail and Yahoo require one-click unsubscribe from bulk senders, and that takes both headers:
 * List-Unsubscribe with an https url, and List-Unsubscribe-Post to say the url accepts a POST.
 *
 * Transactional mail needs neither, which is why this never fails outright.
 */
final readonly class Unsubscribe implements Check
{
    public function run(Context $context): Finding
    {
        $header = $context->header('list-unsubscribe');

        if ($header === null) {
            return Finding::warn(
                'unsubscribe',
                Section::Headers,
                'The message has no List-Unsubscribe header',
                0.5,
                'Required for bulk mail, harmless on a transactional message.',
            );
        }

        $evidence = ["List-Unsubscribe: {$header}"];
        $post = $context->header('list-unsubscribe-post');

        if ($post !== null) {
            $evidence[] = "List-Unsubscribe-Post: {$post}";
        }

        if (!str_contains(strtolower($header), 'https://')) {
            return Finding::warn(
                'unsubscribe',
                Section::Headers,
                'The unsubscribe header offers no https url',
                0.5,
                'One-click unsubscribe needs an https url; a mailto alone does not qualify.',
                $evidence,
            );
        }

        if ($post === null) {
            return Finding::warn(
                'unsubscribe',
                Section::Headers,
                'The message has List-Unsubscribe but not List-Unsubscribe-Post',
                0.5,
                'Without it the url is a link to follow, not a button the client can press for the reader.',
                $evidence,
            );
        }

        return Finding::pass('unsubscribe', Section::Headers, 'One-click unsubscribe is set up', '', $evidence);
    }
}
