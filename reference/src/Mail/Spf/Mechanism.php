<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

/** One mechanism of a record: its qualifier, its name and whatever the name allows after it. */
final readonly class Mechanism
{
    public function __construct(
        public Qualifier $qualifier,
        public string $name,
        public string $term,
        public ?string $target = null,
        public ?int $prefixFour = null,
        public ?int $prefixSix = null,
    ) {}

    /** The prefix to compare with, defaulting to the full width of the client address. */
    public function prefixFor(string $client): int
    {
        $prefix = strlen($client) === 4 ? $this->prefixFour : $this->prefixSix;

        return $prefix ?? Ip::bits($client);
    }
}
