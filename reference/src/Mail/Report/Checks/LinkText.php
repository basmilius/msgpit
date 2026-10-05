<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report\Checks;

use Msgpit\Mail\Report\Check;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Section;

/**
 * A link whose text names one domain and whose href goes to another. That is the shape of a
 * phishing mail, and filters score it as such even when the cause is an innocent tracking domain
 * wrapped around a perfectly real url.
 */
final readonly class LinkText implements Check
{
    public function run(Context $context): Finding
    {
        $document = $context->document();

        if ($document === null) {
            return Finding::skip('link-text', Section::Links, 'Link text', 'The message has no html.');
        }

        $mismatched = [];
        $anchors = $document->getElementsByTagName('a');

        foreach ($anchors as $anchor) {

            $text = trim($anchor->textContent);
            $target = self::host($anchor->getAttribute('href'));

            // Only text that is itself a url makes a claim about where the link goes.
            if ($target === null || preg_match('#\b((?:https?://)?(?:[a-z0-9-]+\.)+[a-z]{2,})#i', $text, $match) !== 1) {
                continue;
            }

            $claimed = self::host(str_starts_with($match[1], 'http') ? $match[1] : "https://{$match[1]}");

            if ($claimed !== null && $claimed !== $target && !str_ends_with($target, ".{$claimed}")) {
                $mismatched[] = "\"{$text}\" goes to {$target}";
            }
        }

        if ($anchors->length === 0) {
            return Finding::pass('link-text', Section::Links, 'The message has no links');
        }

        if ($mismatched === []) {
            return Finding::pass('link-text', Section::Links, "None of the {$anchors->length} links say one thing and do another");
        }

        return Finding::warn(
            'link-text',
            Section::Links,
            count($mismatched) . ' links name a different domain than they open',
            1.0,
            'The shape of a phishing message, whatever the reason for it here.',
            array_slice($mismatched, 0, 10),
        );
    }

    private static function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        // Not ltrim("www."): that strips any leading w or dot, and turns web.nl into eb.nl.
        return is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }
}
