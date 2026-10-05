<?php

declare(strict_types=1);

namespace Msgpit\Core;

/** What the UI asks us to report back to the app. */
enum DeliveryStatus: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function toMessageStatus(): MessageStatus
    {
        return match ($this) {
            self::Delivered => MessageStatus::Delivered,
            self::Failed => MessageStatus::Failed,
        };
    }
}
