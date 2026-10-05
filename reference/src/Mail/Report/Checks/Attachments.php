<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;
use Msgpit\Mime\Part;

/** Types that gateways refuse outright, and a size that gets a message bounced rather than filtered. */
final readonly class Attachments implements Check
{
    private const BLOCKED = ['exe', 'scr', 'com', 'bat', 'cmd', 'pif', 'vbs', 'js', 'jar', 'msi', 'dll', 'iso', 'lnk'];

    private const LARGE = 10 * 1024 * 1024;

    public function run(Context $context): Finding
    {
        $attachments = $context->mail->attachments();

        if ($attachments === []) {
            return Finding::pass('attachments', Section::Content, 'The message has no attachments');
        }

        $total = array_sum(array_map(static fn (Part $part): int => $part->size(), $attachments));
        $evidence = array_map(
            static fn (Part $part): string => sprintf('%s (%s, %d kB)', $part->filename ?? '(unnamed)', $part->contentType, (int) round($part->size() / 1024)),
            $attachments,
        );

        $blocked = array_values(array_filter(
            $attachments,
            static fn (Part $part): bool => in_array(strtolower(pathinfo($part->filename ?? '', PATHINFO_EXTENSION)), self::BLOCKED, true),
        ));

        if ($blocked !== []) {
            return Finding::fail(
                'attachments',
                Section::Content,
                'An attachment has a type mail gateways refuse',
                2.0,
                'Executable types are stripped or the whole message is rejected.',
                $evidence,
            );
        }

        if ($total > self::LARGE) {
            return Finding::warn(
                'attachments',
                Section::Content,
                'The attachments total ' . round($total / 1024 / 1024, 1) . ' MB',
                0.5,
                'Many servers refuse a message over about 10 MB outright.',
                $evidence,
            );
        }

        $count = count($attachments);
        $headline = $count === 1 ? 'One attachment' : "{$count} attachments";

        return Finding::pass('attachments', Section::Content, "{$headline}, nothing a gateway objects to", '', $evidence);
    }
}
