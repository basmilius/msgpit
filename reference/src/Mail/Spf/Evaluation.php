<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

/**
 * What one evaluation spends and observes. The DNS budget of RFC 7208 4.6.4 is a security limit,
 * not a detail: the names being looked up come out of a message and are the sender's to choose,
 * so an unbounded record is an amplifier pointed at whoever runs the check.
 */
final class Evaluation
{
    private const MAX_LOOKUPS = 10;
    private const MAX_VOID_LOOKUPS = 2;

    private int $lookups = 0;

    private int $voidLookups = 0;

    /** @var list<string> */
    private array $notes = [];

    public function spend(string $term): void
    {
        if (++$this->lookups > self::MAX_LOOKUPS) {
            throw EvaluationFailure::permanent(sprintf('The evaluation needs more than %d DNS lookups; "%s" is past the limit.', self::MAX_LOOKUPS, $term));
        }
    }

    /** A lookup that answered with nothing. Two are allowed, the third ends the evaluation. */
    public function countVoid(string $term): void
    {
        if (++$this->voidLookups > self::MAX_VOID_LOOKUPS) {
            throw EvaluationFailure::permanent(sprintf('The evaluation makes more than %d void DNS lookups; "%s" is past the limit.', self::MAX_VOID_LOOKUPS, $term));
        }
    }

    public function lookups(): int
    {
        return $this->lookups;
    }

    public function note(string $note): void
    {
        if (!in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }
}
