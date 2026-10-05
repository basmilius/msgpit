<?php

declare(strict_types=1);

namespace Msgpit\Mail\Dkim;

enum SignatureStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Unsupported = 'unsupported';
    case NoKey = 'no-key';
    case Revoked = 'revoked';
}
