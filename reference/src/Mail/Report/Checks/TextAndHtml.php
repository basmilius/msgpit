<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/** Both bodies or neither: a filter reads the missing half as a sender who took a shortcut. */
final readonly class TextAndHtml implements Check
{
    public function run(Context $context): Finding
    {
        $html = $context->html !== null && trim($context->html) !== '';
        $text = $context->text !== null && trim($context->text) !== '';

        if ($html && $text) {
            return Finding::pass('text-and-html', Section::Content, 'The message has both a text and an html version');
        }

        if ($html) {
            return Finding::warn(
                'text-and-html',
                Section::Content,
                'The message has no text version',
                0.5,
                'Clients that show plain text, and filters weighing the two halves against each other, have nothing to read.',
            );
        }

        if ($text) {
            return Finding::warn(
                'text-and-html',
                Section::Content,
                'The message has no html version',
                0.5,
                'Fine for a notification, worth knowing for anything that is meant to look designed.',
            );
        }

        return Finding::fail('text-and-html', Section::Content, 'The message has no body at all', 2.0);
    }
}
