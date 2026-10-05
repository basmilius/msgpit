<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * Gmail stops rendering a message past roughly 102 kB and hides the rest behind "View entire
 * message". Anything after that point, the unsubscribe link included, is gone for most readers.
 */
final readonly class GmailClipping implements Check
{
    private const LIMIT = 102 * 1024;

    public function run(Context $context): Finding
    {
        if ($context->html === null) {
            return Finding::skip('gmail-clipping', Section::Content, 'Message size', 'The message has no html.');
        }

        $size = strlen($context->html);
        $readable = $size < 1024 ? "{$size} bytes" : round($size / 1024) . ' kB';

        if ($size <= self::LIMIT) {
            return Finding::pass('gmail-clipping', Section::Content, "The html is {$readable}, under Gmail's clipping point");
        }

        return Finding::warn(
            'gmail-clipping',
            Section::Content,
            "The html is {$readable}, over Gmail's clipping point",
            1.0,
            'Gmail cuts a message off around 102 kB and hides the rest behind a link, the footer included.',
        );
    }
}
