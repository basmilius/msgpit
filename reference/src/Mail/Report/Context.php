<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

use DOMDocument;
use Msgpit\Core\Resolver;
use Msgpit\Core\SpamReport;
use Msgpit\Mime\ParsedMessage;

/** Everything the checks read, worked out once instead of per check. */
final class Context
{
    private ?DOMDocument $document = null;

    private bool $parsed = false;

    /** Present only when the network checks were asked for: they are never part of opening a message. */
    public readonly Origin $origin;

    public function __construct(
        public readonly ParsedMessage $mail,
        public readonly ?string $html,
        public readonly ?string $text,
        public readonly ?SpamReport $spam = null,
        public readonly bool $imported = false,
        public readonly ?Resolver $dns = null,
    ) {
        $this->origin = Origin::fromRaw($mail->raw);
    }

    /** The domain the reader sees, which is the one DMARC holds everything against. */
    public function fromDomain(): ?string
    {
        return self::domainOf($this->header('from'));
    }

    public function header(string $name): ?string
    {
        $value = $this->mail->headers[strtolower($name)] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The html as a document, or null when there is none. Mail html is broken by nature, so the
     * parser errors are swallowed: we judge what a client would render, not whether it validates.
     */
    public function document(): ?DOMDocument
    {
        if ($this->parsed) {
            return $this->document;
        }

        $this->parsed = true;

        if ($this->html === null || trim($this->html) === '') {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8"?>' . $this->html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $this->document = $loaded ? $document : null;
    }

    /** The domain of an address header, lowercased, or null when there is no address in it. */
    public static function domainOf(?string $address): ?string
    {
        if ($address === null || preg_match('/([^\s<>@]+)@([^\s<>@,;]+)/', $address, $match) !== 1) {
            return null;
        }

        return strtolower(rtrim($match[2], '.>'));
    }
}
