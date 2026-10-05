<?php

declare(strict_types=1);

namespace Msgpit\Core;

enum MessageStatus: string
{
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
