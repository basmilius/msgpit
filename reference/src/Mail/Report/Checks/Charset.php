<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;
use Msgpit\Mime\Part;

/**
 * A body that is not what it claims to be arrives as mojibake, and nothing downstream repairs it.
 *
 * Read from the text parts, not from the top level: a multipart message declares its type there
 * and never a character set, so looking in the obvious place reports every message with an
 * attachment as undeclared.
 */
final readonly class Charset implements Check
{
    public function run(Context $context): Finding
    {
        $texts = array_values(array_filter($context->mail->parts, static fn (Part $part): bool => $part->isText()));

        if ($texts === []) {
            return Finding::skip('charset', Section::Content, 'Character set', 'The message has no text body.');
        }

        $declared = array_values(array_unique(array_map(
            static fn (Part $part): string => strtolower($part->charset ?? ''),
            $texts,
        )));
        $evidence = array_map(
            static fn (Part $part): string => $part->contentType . '; charset=' . ($part->charset ?? '(none)'),
            $texts,
        );

        if (in_array('', $declared, true)) {
            return Finding::warn(
                'charset',
                Section::Content,
                'A body declares no character set',
                0.5,
                'Clients then guess, and they do not all guess the same.',
                $evidence,
            );
        }

        foreach ($texts as $part) {
            // The parser has already decoded to utf-8, so anything invalid here is a body that did
            // not hold what it said it held.
            if (!mb_check_encoding($part->content, 'UTF-8')) {
                return Finding::fail(
                    'charset',
                    Section::Content,
                    'A body does not match the character set it declares',
                    1.0,
                    'Characters outside the declared set arrive as mojibake.',
                    $evidence,
                );
            }
        }

        return Finding::pass('charset', Section::Content, 'Every body declares a character set and matches it', '', $evidence);
    }
}
