<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

use RuntimeException;

/**
 * A permerror or temperror, thrown rather than returned so it travels straight out of a nested
 * include without every caller having to hand the same verdict upwards.
 */
final class EvaluationFailure extends RuntimeException
{
    public function __construct(
        public readonly SpfStatus $status,
        string $reason,
        public readonly ?string $record = null,
    ) {
        parent::__construct($reason);
    }

    public static function permanent(string $reason, ?string $record = null): self
    {
        return new self(SpfStatus::PermError, $reason, $record);
    }

    public static function transient(string $reason, ?string $record = null): self
    {
        return new self(SpfStatus::TempError, $reason, $record);
    }
}
