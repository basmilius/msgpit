<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * Elements no mail client will run and every filter will notice. Their presence says more about
 * the template that produced the message than about any risk to the reader.
 */
final readonly class DangerousHtml implements Check
{
    private const ELEMENTS = ['script', 'iframe', 'frame', 'frameset', 'embed', 'object', 'applet', 'form'];

    public function run(Context $context): Finding
    {
        $document = $context->document();

        if ($document === null) {
            return Finding::skip('dangerous-html', Section::Content, 'Dangerous html', 'The message has no html.');
        }

        $found = [];

        foreach (self::ELEMENTS as $name) {
            $count = $document->getElementsByTagName($name)->length;

            if ($count > 0) {
                $found[] = $count === 1 ? "<{$name}>" : "<{$name}> x{$count}";
            }
        }

        if ($found === []) {
            return Finding::pass('dangerous-html', Section::Content, 'No scripts, frames or embedded content');
        }

        return Finding::fail(
            'dangerous-html',
            Section::Content,
            'The html contains elements mail clients strip',
            2.0,
            'They will not run, and a filter counts them against the message.',
            $found,
        );
    }
}
