<?php

declare(strict_types=1);

namespace Msgpit\Smtp;

/** One accepted message: who the SMTP dialogue said it was for, and the bytes that followed. */
final readonly class Envelope
{
    /** @param list<string> $recipients From RCPT TO, which is what actually decides delivery. */
    public function __construct(
        public string $sender,
        public array $recipients,
        public string $data,
    ) {}
}
