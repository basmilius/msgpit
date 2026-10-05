<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

/**
 * One verdict, with the evidence behind it. The evidence is the point: "no List-Unsubscribe" is
 * an opinion, the header we did read is a fact, and only the second one tells you where to look.
 */
final readonly class Finding
{
    /** @param list<string> $evidence */
    public function __construct(
        public string $id,
        public Section $section,
        public string $title,
        public Status $status,
        public float $penalty,
        public string $explanation,
        public array $evidence = [],
    ) {}

    /** @param list<string> $evidence */
    public static function pass(string $id, Section $section, string $title, string $explanation = '', array $evidence = []): self
    {
        return new self($id, $section, $title, Status::Pass, 0.0, $explanation, $evidence);
    }

    /** @param list<string> $evidence */
    public static function warn(string $id, Section $section, string $title, float $penalty, string $explanation = '', array $evidence = []): self
    {
        return new self($id, $section, $title, Status::Warn, $penalty, $explanation, $evidence);
    }

    /** @param list<string> $evidence */
    public static function fail(string $id, Section $section, string $title, float $penalty, string $explanation = '', array $evidence = []): self
    {
        return new self($id, $section, $title, Status::Fail, $penalty, $explanation, $evidence);
    }

    public static function skip(string $id, Section $section, string $title, string $explanation): self
    {
        return new self($id, $section, $title, Status::Skip, 0.0, $explanation);
    }

    public function counts(): bool
    {
        return $this->status !== Status::Skip;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'section' => $this->section->value,
            'title' => $this->title,
            'status' => $this->status->value,
            'penalty' => $this->penalty,
            'explanation' => $this->explanation,
            'evidence' => $this->evidence,
        ];
    }
}
