<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

enum Section: string
{
    case Spam = 'spam';
    case Authentication = 'authentication';
    case Reputation = 'reputation';
    case Content = 'content';
    case Headers = 'headers';
    case Links = 'links';

    public function title(): string
    {
        return match ($this) {
            self::Spam => 'Spam filters',
            self::Authentication => 'Authentication',
            self::Reputation => 'Reputation',
            self::Content => 'Message content',
            self::Headers => 'Headers',
            self::Links => 'Links',
        };
    }
}
