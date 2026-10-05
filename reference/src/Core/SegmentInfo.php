<?php

declare(strict_types=1);

namespace Msgpit\Core;

final readonly class SegmentInfo
{
    /**
     * @param int $characters Codepoints in the body.
     * @param int $units Septets (GSM-7) or UTF-16 code units (UCS-2); what the segment limit counts.
     * @param list<int> $ucs2Offsets Codepoint offsets that forced UCS-2, for highlighting in the UI.
     */
    public function __construct(
        public Encoding $encoding,
        public int $segments,
        public int $characters,
        public int $units,
        public array $ucs2Offsets = [],
    ) {}
}
