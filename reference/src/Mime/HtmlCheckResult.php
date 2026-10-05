<?php

declare(strict_types=1);

namespace Msgpit\Mime;

final readonly class HtmlCheckResult
{
    /** @param list<HtmlCheckFinding> $findings */
    public function __construct(
        public array $findings,
        public ?string $dataUpdated = null,
    ) {}

    public function tested(): int
    {
        return array_sum(array_map(static fn (HtmlCheckFinding $f): int => $f->tested(), $this->findings));
    }

    /**
     * The headline number: of every client version tested against every feature this message
     * uses, how many support it outright. Weighting each feature by how often it occurs would
     * flatter a message that repeats one safe property, so every feature counts once.
     */
    public function percentage(string $kind = 'supported'): float
    {
        $tested = $this->tested();

        if ($tested === 0) {
            return 0.0;
        }

        $count = array_sum(array_map(
            static fn (HtmlCheckFinding $f): int => match ($kind) {
                'partial' => $f->partial,
                'unsupported' => $f->unsupported,
                default => $f->supported,
            },
            $this->findings,
        ));

        return round($count / $tested * 100, 2);
    }

    /** @return list<HtmlCheckFinding> The ones worth looking at, worst first. */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (HtmlCheckFinding $f): bool => $f->partial > 0 || $f->unsupported > 0,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'supported' => $this->percentage('supported'),
            'partial' => $this->percentage('partial'),
            'unsupported' => $this->percentage('unsupported'),
            'tested' => $this->tested(),
            'features' => count($this->findings),
            'dataUpdated' => $this->dataUpdated,
            'warnings' => array_map(
                static fn (HtmlCheckFinding $f): array => $f->toArray(),
                $this->warnings(),
            ),
        ];
    }
}
