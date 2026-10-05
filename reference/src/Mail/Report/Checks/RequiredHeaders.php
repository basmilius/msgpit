<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * The headers every message is expected to carry. Their absence is a reliable sign of a script
 * that assembled the message by hand, which is exactly what filters are looking for.
 */
final readonly class RequiredHeaders implements Check
{
    private const REQUIRED = ['from', 'to', 'subject', 'date', 'message-id'];

    public function run(Context $context): Finding
    {
        $missing = [];
        $present = [];

        foreach (self::REQUIRED as $name) {
            $value = $context->header($name);

            if ($value === null) {
                $missing[] = ucfirst($name === 'message-id' ? 'Message-ID' : $name);

                continue;
            }

            $present[] = ($name === 'message-id' ? 'Message-ID' : ucfirst($name)) . ': ' . $value;
        }

        if ($missing === []) {
            return Finding::pass('required-headers', Section::Headers, 'All the expected headers are there', '', $present);
        }

        return Finding::fail(
            'required-headers',
            Section::Headers,
            'Missing headers: ' . implode(', ', $missing),
            0.5 * count($missing),
            'A message without them looks assembled rather than sent.',
            $present,
        );
    }
}
