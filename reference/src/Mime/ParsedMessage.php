<?php

declare(strict_types=1);

namespace Msgpit\Mime;

final readonly class ParsedMessage
{
    /**
     * @param array<string, string> $headers Lowercased names, decoded values.
     * @param list<Part> $parts Flattened: the multipart tree itself carries no information the
     *                          reader needs, only which part is text, which is html, which is an
     *                          image the html points at, and which is an attachment.
     */
    public function __construct(
        public array $headers,
        public string $subject,
        public string $from,
        public string $to,
        public array $parts,
        public string $raw,
    ) {}

    public function text(): ?string
    {
        foreach ($this->parts as $part) {
            if ($part->isText() && !$part->isAttachment()) {
                return $part->content;
            }
        }

        return null;
    }

    public function html(): ?string
    {
        foreach ($this->parts as $part) {
            if ($part->isHtml() && !$part->isAttachment()) {
                return $part->content;
            }
        }

        return null;
    }

    /** What the list and the notification show: the text version, or the html stripped down. */
    public function preview(): string
    {
        $text = $this->text();

        if ($text !== null && trim($text) !== '') {
            return trim($text);
        }

        $html = $this->html();

        if ($html === null) {
            return '';
        }

        $stripped = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($stripped))));
    }

    /** @return list<Part> */
    public function attachments(): array
    {
        return array_values(array_filter($this->parts, static fn (Part $part): bool => $part->isAttachment()));
    }

    /** @return list<Part> Images and the like that the html refers to by cid. */
    public function inlineParts(): array
    {
        return array_values(array_filter($this->parts, static fn (Part $part): bool => $part->isInline()));
    }

    public function partByContentId(string $contentId): ?Part
    {
        foreach ($this->parts as $part) {
            if ($part->contentId === trim($contentId, '<>')) {
                return $part;
            }
        }

        return null;
    }

    /**
     * The addresses in a To, Cc or Bcc header. Split by hand rather than by regex, because a
     * comma is only a separator outside quotes and outside angle brackets: "Jansen, Piet" is one
     * recipient, not two.
     *
     * @return list<string>
     */
    public static function addresses(string $header): array
    {
        $addresses = [];
        $current = '';
        $inQuotes = false;
        $inAngles = false;

        foreach (str_split($header) as $character) {
            if ($character === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($character === '<' && !$inQuotes) {
                $inAngles = true;
            } elseif ($character === '>' && !$inQuotes) {
                $inAngles = false;
            } elseif ($character === ',' && !$inQuotes && !$inAngles) {
                $addresses[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $addresses[] = $current;

        $cleaned = [];

        foreach ($addresses as $entry) {
            $address = preg_match('/<([^>]+)>/', $entry, $matches) === 1 ? $matches[1] : $entry;
            $address = trim($address);

            if ($address !== '') {
                $cleaned[] = $address;
            }
        }

        return $cleaned;
    }
}
