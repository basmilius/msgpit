<?php

declare(strict_types=1);

namespace Msgpit\Core;

use Msgpit\Http\Response;

/** What a provider hands back: what to store, and what the app gets to see. */
final readonly class Capture
{
    /** @param list<Message> $messages May be empty, for endpoints that send nothing. */
    public function __construct(
        public array $messages,
        public Response $response,
    ) {}
}
