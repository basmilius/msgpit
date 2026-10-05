<?php

declare(strict_types=1);

namespace Msgpit\Mime;

/** One MIME part, already decoded from its transfer encoding. */
final readonly class Part
{
    /** @param array<string, string> $headers Header names lowercased. */
    public function __construct(
        public string $contentType,
        public string $content,
        public array $headers = [],
        public ?string $contentId = null,
        public ?string $filename = null,
        public ?string $disposition = null,
        public ?string $charset = null,
    ) {}

    public function isText(): bool
    {
        return $this->contentType === 'text/plain';
    }

    public function isHtml(): bool
    {
        return $this->contentType === 'text/html';
    }

    /**
     * A part the message refers to rather than offers: an image behind a cid: in the HTML.
     * Everything else with a filename is something the reader is meant to save.
     */
    public function isInline(): bool
    {
        return $this->contentId !== null || $this->disposition === 'inline';
    }

    public function isAttachment(): bool
    {
        if ($this->isInline()) {
            return false;
        }

        return $this->disposition === 'attachment' || ($this->filename !== null && !$this->isText() && !$this->isHtml());
    }

    public function size(): int
    {
        return strlen($this->content);
    }
}
