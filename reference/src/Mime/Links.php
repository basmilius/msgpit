<?php

declare(strict_types=1);

namespace Msgpit\Mime;

/** Finds the unique links in a message: the ones a reader can click and the ones a client loads. */
final class Links
{
    /** Where a url can sit in mail html. */
    private const SOURCES = [
        'a' => 'href',
        'area' => 'href',
        'img' => 'src',
        'source' => 'src',
        'link' => 'href',
        'video' => 'poster',
    ];

    /**
     * @return list<array{url: string, kind: string}> kind is "link" for something clickable and
     *         "resource" for something the client fetches on its own.
     */
    public static function find(?string $html, ?string $text): array
    {
        $found = [];

        foreach (self::fromHtml($html ?? '') as $url => $kind) {
            $found[$url] = $kind;
        }

        foreach (self::fromText($text ?? '') as $url) {
            // A url in both bodies is one link; clickable wins over resource.
            $found[$url] ??= 'link';
        }

        $links = [];

        foreach ($found as $url => $kind) {
            $links[] = ['url' => (string) $url, 'kind' => $kind];
        }

        return $links;
    }

    /** @return array<string, string> */
    private static function fromHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $found = [];

        // A bare url in the body text gets linked by most clients, so it counts as a link.
        foreach (self::fromText($document->textContent) as $url) {
            $found[$url] = 'link';
        }

        foreach ((new \DOMXPath($document))->query('//*') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            $attribute = self::SOURCES[$tag] ?? null;

            if ($attribute !== null) {
                $url = self::normalise($node->getAttribute($attribute));

                if ($url !== null) {
                    $found[$url] = $tag === 'a' || $tag === 'area' ? 'link' : 'resource';
                }
            }

            // Backgrounds and fonts hide in css, and a client fetches those too.
            $style = $node->getAttribute('style') . ' ' . ($tag === 'style' ? $node->textContent : '');

            if (preg_match_all('/url\(\s*[\'"]?([^\'")]+)/i', $style, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $candidate) {
                $url = self::normalise($candidate);

                if ($url !== null) {
                    $found[$url] ??= 'resource';
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private static function fromText(string $text): array
    {
        if (preg_match_all('#\bhttps?://[^\s<>"\'\])]+#i', $text, $matches) === false) {
            return [];
        }

        $urls = [];

        foreach ($matches[0] as $candidate) {
            // Trailing punctuation belongs to the sentence, not the url.
            $url = self::normalise(rtrim($candidate, '.,;:!?'));

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /** Only what a client would actually fetch: no cid:, mailto:, tel:, data: or anchors. */
    private static function normalise(string $candidate): ?string
    {
        $url = trim(html_entity_decode($candidate));

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }
}
