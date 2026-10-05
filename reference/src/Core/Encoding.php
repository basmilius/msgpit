<?php

declare(strict_types=1);

namespace Msgpit\Core;

enum Encoding: string
{
    case Gsm7 = 'GSM-7';
    case Ucs2 = 'UCS-2';
}
