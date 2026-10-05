<?php

declare(strict_types=1);

namespace Msgpit\Mime;

/** One thing the message uses, and how the clients handle it. */
final readonly class HtmlCheckFinding
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $category,
        public int $occurrences,
        public int $supported,
        public int $partial,
        public int $unsupported,
    ) {}

    public function tested(): int
    {
        return $this->supported + $this->partial + $this->unsupported;
    }

    /** Share of tested clients that support it outright, as a fraction. */
    public function score(): float
    {
        return $this->tested() === 0 ? 1.0 : $this->supported / $this->tested();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'category' => $this->category,
            'occurrences' => $this->occurrences,
            'supported' => $this->supported,
            'partial' => $this->partial,
            'unsupported' => $this->unsupported,
            'tested' => $this->tested(),
        ];
    }
}
