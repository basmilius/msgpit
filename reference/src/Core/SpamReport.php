<?php

declare(strict_types=1);

namespace Msgpit\Core;

final readonly class SpamReport
{
    /** @param list<array{points: float, name: string, description: string}> $rules */
    public function __construct(
        public bool $spam,
        public float $score,
        public float $threshold,
        public array $rules,
        public string $report,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'spam' => $this->spam,
            'score' => $this->score,
            'threshold' => $this->threshold,
            'rules' => $this->rules,
            'report' => $this->report,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var list<array{points: float, name: string, description: string}> $rules */
        $rules = is_array($data['rules'] ?? null) ? array_values($data['rules']) : [];

        $score = $data['score'] ?? 0;
        $threshold = $data['threshold'] ?? 5;

        return new self(
            spam: (bool) ($data['spam'] ?? false),
            score: is_numeric($score) ? (float) $score : 0.0,
            threshold: is_numeric($threshold) ? (float) $threshold : 5.0,
            rules: $rules,
            report: is_string($data['report'] ?? null) ? $data['report'] : '',
        );
    }
}
