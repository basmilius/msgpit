<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/** The display name is the first thing in an inbox list, and a bare address reads as machinery. */
final readonly class SenderName implements Check
{
    public function run(Context $context): Finding
    {
        $from = $context->header('from');

        if ($from === null) {
            return Finding::skip('sender-name', Section::Headers, 'Sender name', 'The message has no From header.');
        }

        if (!str_contains($from, '<')) {
            return Finding::warn(
                'sender-name',
                Section::Headers,
                'The sender has no display name',
                0.5,
                'Inboxes show the bare address, which reads as automated post.',
                ["From: {$from}"],
            );
        }

        return Finding::pass('sender-name', Section::Headers, 'The sender has a display name', '', ["From: {$from}"]);
    }
}
