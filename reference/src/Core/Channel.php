<?php

declare(strict_types=1);

namespace Msgpit\Core;

/** Belongs to the message, not the provider: one provider can serve several channels. */
enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';
    case WhatsApp = 'whatsapp';
}
