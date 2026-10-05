<?php

declare(strict_types=1);

namespace Msgpit\Core;

interface Provider
{
    /** Stable id, also the route prefix. Lowercase, a-z0-9 only. */
    public function id(): string;

    /** @return list<Route> */
    public function routes(): array;

    /** @return list<Channel> Channels this provider can deliver. */
    public function channels(): array;
}
