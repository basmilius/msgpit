<?php

declare(strict_types=1);

namespace Msgpit\Mime;

/**
 * Scores the html of a message against what email clients actually support.
 *
 * Email clients are a decade behind browsers and disagree with each other, so html that looks
 * right in the preview can still fall apart in Outlook. This walks the document, collects the
 * elements, attributes and css properties it uses, and looks each one up in the caniemail data
 * bundled at data/caniemail.json.
 *
 * Data by Rémi Parmentier, MIT licensed. https://www.caniemail.com
 */
final class HtmlCheck
{
    /** Attributes every document has, or that say nothing about compatibility. */
    private const IGNORED_ATTRIBUTES = ['class', 'id', 'style', 'href', 'src', 'alt', 'title', 'lang', 'charset', 'content', 'name'];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $features = null;

    private static ?string $updated = null;

    public static function analyse(string $html, ?string $dataPath = null): ?HtmlCheckResult
    {
        $features = self::features($dataPath);

        if ($features === []) {
            return null;
        }

        $used = self::collect($html);
        $findings = [];

        foreach ($used as $slug => $occurrences) {
            $feature = $features[$slug] ?? null;

            if ($feature === null || !is_array($feature['stats'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $stats */
            $stats = $feature['stats'];
            $support = self::support($stats);

            // Nothing tested means nothing to say about it.
            if ($support['total'] === 0) {
                continue;
            }

            $findings[] = new HtmlCheckFinding(
                slug: $slug,
                title: is_string($feature['title'] ?? null) ? $feature['title'] : $slug,
                category: is_string($feature['category'] ?? null) ? $feature['category'] : 'other',
                occurrences: $occurrences,
                supported: $support['y'],
                partial: $support['a'],
                unsupported: $support['n'],
            );
        }

        if ($findings === []) {
            return null;
        }

        // Worst first: that is the order in which you would want to fix them.
        usort($findings, static fn (HtmlCheckFinding $a, HtmlCheckFinding $b): int
            => $a->score() <=> $b->score() ?: strcmp($a->title, $b->title));

        return new HtmlCheckResult($findings, self::$updated);
    }

    /**
     * Everything in the document that caniemail has an opinion about: element names, attribute
     * names, and css property names from both inline styles and style elements.
     *
     * @return array<string, int> Slug to how often it occurs.
     */
    private static function collect(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // Mail html is rarely well formed, and its errors are not ours to report.
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $used = [];
        $add = static function (string $slug) use (&$used): void {
            $used[$slug] = ($used[$slug] ?? 0) + 1;
        };

        foreach ((new \DOMXPath($document))->query('//*') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $add('html-' . strtolower($node->tagName));

            foreach ($node->attributes ?? [] as $attribute) {
                $name = strtolower($attribute->name);

                if (!in_array($name, self::IGNORED_ATTRIBUTES, true)) {
                    $add('html-' . $name);
                }
            }

            $style = $node->getAttribute('style');

            if ($style !== '') {
                foreach (self::properties($style) as $property) {
                    $add('css-' . $property);
                }
            }

            if (strtolower($node->tagName) === 'style') {
                foreach (self::properties($node->textContent) as $property) {
                    $add('css-' . $property);
                }
            }
        }

        return $used;
    }

    /**
     * Property names from a declaration block or a whole stylesheet. Deliberately not a css
     * parser: we only need the names on the left of a colon, and a regex sees those fine.
     *
     * @return list<string>
     */
    private static function properties(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', ' ', $css);
        $properties = [];

        if (preg_match_all('/(?:^|[;{])\s*([a-zA-Z-]+)\s*:/m', $css, $matches) !== false) {
            foreach ($matches[1] as $property) {
                $properties[] = strtolower($property);
            }
        }

        return array_values(array_unique($properties));
    }

    /**
     * How the clients score on one feature, counting every client version that was tested. An
     * "unknown" is not evidence either way, so it does not count at all.
     *
     * @param array<string, mixed> $stats
     * @return array{y: int, a: int, n: int, total: int}
     */
    private static function support(array $stats): array
    {
        $counts = ['y' => 0, 'a' => 0, 'n' => 0, 'total' => 0];

        array_walk_recursive($stats, static function (mixed $value) use (&$counts): void {
            if (!is_string($value)) {
                return;
            }

            // Values look like "y", "n", "a #2" or "u"; the note number is not our concern.
            $verdict = strtolower(substr(trim($value), 0, 1));

            if (isset($counts[$verdict])) {
                $counts[$verdict]++;
                $counts['total']++;
            }
        });

        return $counts;
    }

    /** @return array<string, array<string, mixed>> */
    private static function features(?string $dataPath): array
    {
        if (self::$features !== null) {
            return self::$features;
        }

        $path = $dataPath ?? dirname(__DIR__, 2) . '/data/caniemail.json';

        if (!is_file($path)) {
            self::$features = [];

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            self::$features = [];

            return [];
        }

        self::$updated = is_string($decoded['last_update_date'] ?? null) ? $decoded['last_update_date'] : null;
        self::$features = [];

        $features = [];

        foreach ($decoded['data'] as $feature) {
            if (!is_array($feature)) {
                continue;
            }

            $slug = $feature['slug'] ?? null;

            if (is_string($slug)) {
                /** @var array<string, mixed> $feature */
                $features[$slug] = $feature;
            }
        }

        self::$features = $features;

        return $features;
    }

    /** @internal For tests that need a clean slate. */
    public static function forget(): void
    {
        self::$features = null;
        self::$updated = null;
    }
}
