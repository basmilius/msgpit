<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

enum Qualifier: string
{
    case Pass = '+';
    case Fail = '-';
    case SoftFail = '~';
    case Neutral = '?';

    public function status(): SpfStatus
    {
        return match ($this) {
            self::Pass => SpfStatus::Pass,
            self::Fail => SpfStatus::Fail,
            self::SoftFail => SpfStatus::SoftFail,
            self::Neutral => SpfStatus::Neutral,
        };
    }
}
