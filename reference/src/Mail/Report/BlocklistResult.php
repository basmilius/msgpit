<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

final readonly class BlocklistResult
{
    /** @param list<string> $codes */
    public function __construct(
        public string $name,
        public string $zone,
        public array $codes,
        public BlocklistVerdict $verdict,
    ) {}

    public function describe(): string
    {
        $codes = implode(', ', $this->codes);

        return match ($this->verdict) {
            BlocklistVerdict::Clean => "Not listed in {$this->name}",
            BlocklistVerdict::Good => "{$this->name} knows this address as a good one ({$codes})",
            BlocklistVerdict::Caution => "Listed in {$this->name} on a policy list, not as a spam source ({$codes})",
            BlocklistVerdict::Listed => "Listed in {$this->name} ({$codes})",
            BlocklistVerdict::Refused => "{$this->name} declined to answer, which it does for queries through a public resolver",
        };
    }
}
