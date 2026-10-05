<?php

declare(strict_types=1);

namespace Msgpit\Core;

use Msgpit\Http\OutgoingRequest;

interface SupportsDeliveryReports
{
    /** The callback the real provider would send, or null when no callback URL is known. */
    public function deliveryReport(Message $message, DeliveryStatus $status): ?OutgoingRequest;
}
