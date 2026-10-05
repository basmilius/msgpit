<?php

declare(strict_types=1);

namespace Msgpit\Core;

/**
 * What the report needs from DNS, which is a little more than SPF does: a reverse lookup, and a
 * way to say the whole thing is switched off. Extends the SPF interface so one implementation
 * serves both, and so a test can answer from a table without a network.
 */
interface Resolver extends \Msgpit\Mail\Spf\Resolver
{
    public function enabled(): bool;

    public function reverse(string $ip): ?string;

    public function lookups(): int;
}
