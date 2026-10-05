<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

enum Status: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';

    /**
     * The check does not apply to this message. A mail we caught ourselves never travelled, so it
     * has no sending server to judge. Skipped checks are listed but left out of the score: counting
     * them would mark every test mail down for something it cannot have.
     */
    case Skip = 'skip';
}
