<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

/** The check_host() results of RFC 7208 2.6. */
enum SpfStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case SoftFail = 'softfail';
    case Neutral = 'neutral';
    case None = 'none';
    case PermError = 'permerror';
    case TempError = 'temperror';
}
