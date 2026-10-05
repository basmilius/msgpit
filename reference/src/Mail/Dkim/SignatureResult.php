<?php

declare(strict_types=1);

namespace Msgpit\Mail\Dkim;

final readonly class SignatureResult
{
    /** @param list<string> $notes Observations that do not decide the verdict, such as a key in testing mode. */
    public function __construct(
        public string $domain,
        public string $selector,
        public string $algorithm,
        public SignatureStatus $status,
        public string $reason,
        public array $notes = [],
    ) {}

    /** @param list<string> $notes */
    public static function of(
        Signature $signature,
        SignatureStatus $status,
        string $reason,
        array $notes = [],
    ): self {
        return new self(
            domain: $signature->domain,
            selector: $signature->selector,
            algorithm: $signature->algorithm,
            status: $status,
            reason: $reason,
            notes: $notes,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'selector' => $this->selector,
            'algorithm' => $this->algorithm,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'notes' => $this->notes,
        ];
    }
}
