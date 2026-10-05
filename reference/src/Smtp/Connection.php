<?php

declare(strict_types=1);

namespace Msgpit\Smtp;

/** @internal One open socket and the conversation running over it. */
final class Connection
{
    public string $buffer = '';

    private int $lastActivity;

    /** @param resource $socket */
    public function __construct(
        public readonly mixed $socket,
        public readonly Session $session,
    ) {
        $this->lastActivity = time();
    }

    public function touch(): void
    {
        $this->lastActivity = time();
    }

    public function idleFor(): int
    {
        return time() - $this->lastActivity;
    }
}
