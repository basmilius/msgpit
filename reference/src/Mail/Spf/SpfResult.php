<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

final readonly class SpfResult
{
    /**
     * @param string|null $record The record that decided, as published.
     * @param string|null $mechanism The term that matched, null when nothing did.
     * @param list<string> $notes Observations that do not decide the verdict, such as a ptr.
     */
    public function __construct(
        public SpfStatus $status,
        public string $reason,
        public ?string $record = null,
        public ?string $mechanism = null,
        public int $lookups = 0,
        public array $notes = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason,
            'record' => $this->record,
            'mechanism' => $this->mechanism,
            'lookups' => $this->lookups,
            'notes' => $this->notes,
        ];
    }
}
