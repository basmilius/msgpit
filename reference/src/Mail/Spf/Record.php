<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

/** A parsed v=spf1 record: its mechanisms in order, plus the redirect modifier if it has one. */
final readonly class Record
{
    private const NAMES = ['all', 'ip4', 'ip6', 'a', 'mx', 'ptr', 'exists', 'include'];

    /** @param list<Mechanism> $mechanisms */
    private function __construct(
        public string $raw,
        public array $mechanisms,
        public ?string $redirect,
    ) {}

    public static function looksLikeSpf(string $record): bool
    {
        return preg_match('/^v=spf1(\s|$)/i', $record) === 1;
    }

    /** @throws EvaluationFailure on anything the evaluator cannot act on, which is a permerror. */
    public static function parse(string $record): self
    {
        $terms = preg_split('/\s+/', trim($record)) ?: [];
        array_shift($terms);

        $mechanisms = [];
        $redirect = null;

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            $modifier = self::modifier($term);

            if ($modifier === null) {
                $mechanisms[] = self::mechanism($term, $record);

                continue;
            }

            [$name, $value] = $modifier;

            if ($name !== 'redirect') {
                continue;
            }

            if ($redirect !== null) {
                throw EvaluationFailure::permanent('The record carries more than one redirect modifier.', $record);
            }

            self::rejectMacros($value, $term, $record);
            $redirect = self::domain($value, $term, $record);
        }

        return new self(trim($record), $mechanisms, $redirect);
    }

    /**
     * Unknown modifiers are ignored by RFC 7208 6, so only the name and value come back here.
     *
     * @return array{string, string}|null
     */
    private static function modifier(string $term): ?array
    {
        if (preg_match('/^(?<name>[a-z][a-z0-9_.-]*)=(?<value>.*)$/i', $term, $match) !== 1) {
            return null;
        }

        return [strtolower($match['name']), $match['value']];
    }

    private static function mechanism(string $term, string $record): Mechanism
    {
        if (preg_match('/^(?<qualifier>[+\-~?])?(?<name>[a-z0-9]+)(?<rest>[:\/].*)?$/i', $term, $match) !== 1) {
            throw EvaluationFailure::permanent(sprintf('The term "%s" is not a mechanism or a modifier.', $term), $record);
        }

        $qualifier = Qualifier::from($match['qualifier'] === '' ? '+' : $match['qualifier']);
        $name = strtolower($match['name']);
        $rest = $match['rest'] ?? '';

        if (!in_array($name, self::NAMES, true)) {
            throw EvaluationFailure::permanent(sprintf('The mechanism "%s" does not exist in SPF.', $name), $record);
        }

        self::rejectMacros($rest, $term, $record);

        return match ($name) {
            'all', 'ptr' => self::bare($qualifier, $name, $term, $rest, $record),
            'ip4', 'ip6' => self::ip($qualifier, $name, $term, $rest, $record),
            'include', 'exists' => self::named($qualifier, $name, $term, $rest, $record),
            default => self::hosted($qualifier, $name, $term, $rest, $record),
        };
    }

    /** ptr takes an optional domain, but msgpit never evaluates it, so the value is not kept. */
    private static function bare(Qualifier $qualifier, string $name, string $term, string $rest, string $record): Mechanism
    {
        if ($name === 'all' && $rest !== '') {
            throw EvaluationFailure::permanent(sprintf('The mechanism "%s" takes no value.', $term), $record);
        }

        return new Mechanism($qualifier, $name, $term);
    }

    private static function ip(Qualifier $qualifier, string $name, string $term, string $rest, string $record): Mechanism
    {
        if (!str_starts_with($rest, ':')) {
            throw EvaluationFailure::permanent(sprintf('The mechanism "%s" needs an address.', $term), $record);
        }

        $value = substr($rest, 1);
        $slash = strrpos($value, '/');
        $literal = $slash === false ? $value : substr($value, 0, $slash);
        $width = $name === 'ip4' ? 32 : 128;

        $address = $name === 'ip4' ? Ip::parseFour($literal) : Ip::parseSix($literal);

        if ($address === null) {
            throw EvaluationFailure::permanent(sprintf('The mechanism "%s" does not carry a valid %s address.', $term, $name === 'ip4' ? 'IPv4' : 'IPv6'), $record);
        }

        $prefix = $slash === false ? $width : self::prefix(substr($value, $slash + 1), $width, $term, $record);

        return new Mechanism(
            qualifier: $qualifier,
            name: $name,
            term: $term,
            target: $address,
            prefixFour: $name === 'ip4' ? $prefix : null,
            prefixSix: $name === 'ip6' ? $prefix : null,
        );
    }

    private static function named(Qualifier $qualifier, string $name, string $term, string $rest, string $record): Mechanism
    {
        if (!str_starts_with($rest, ':')) {
            throw EvaluationFailure::permanent(sprintf('The mechanism "%s" needs a domain.', $term), $record);
        }

        return new Mechanism($qualifier, $name, $term, self::domain(substr($rest, 1), $term, $record));
    }

    /** a and mx: an optional domain and a dual cidr length, as in a:example.com/24//64. */
    private static function hosted(Qualifier $qualifier, string $name, string $term, string $rest, string $record): Mechanism
    {
        $slash = strpos($rest, '/');
        $host = $slash === false ? $rest : substr($rest, 0, $slash);
        $cidr = $slash === false ? '' : substr($rest, $slash);

        $target = null;

        if ($host !== '') {
            if (!str_starts_with($host, ':')) {
                throw EvaluationFailure::permanent(sprintf('The mechanism "%s" is malformed.', $term), $record);
            }

            $target = self::domain(substr($host, 1), $term, $record);
        }

        $prefixFour = null;
        $prefixSix = null;

        if ($cidr !== '') {
            if (preg_match('#^//(\d{1,3})$#', $cidr, $match) === 1) {
                $prefixSix = self::prefix($match[1], 128, $term, $record);
            } elseif (preg_match('#^/(\d{1,3})(?://(\d{1,3}))?$#', $cidr, $match) === 1) {
                $prefixFour = self::prefix($match[1], 32, $term, $record);
                $prefixSix = isset($match[2]) ? self::prefix($match[2], 128, $term, $record) : null;
            } else {
                throw EvaluationFailure::permanent(sprintf('The prefix length in "%s" is malformed.', $term), $record);
            }
        }

        return new Mechanism($qualifier, $name, $term, $target, $prefixFour, $prefixSix);
    }

    private static function prefix(string $value, int $width, string $term, string $record): int
    {
        if (preg_match('/^\d{1,3}$/', $value) !== 1 || (int) $value > $width) {
            throw EvaluationFailure::permanent(sprintf('The prefix length in "%s" is not between 0 and %d.', $term, $width), $record);
        }

        return (int) $value;
    }

    private static function domain(string $domain, string $term, string $record): string
    {
        $domain = rtrim($domain, '.');

        if ($domain === '' || preg_match('/^[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)*$/i', $domain) !== 1) {
            throw EvaluationFailure::permanent(sprintf('The domain in "%s" is not a valid name.', $term), $record);
        }

        return $domain;
    }

    /**
     * Macros are rare in practice and expanding them wrong would hand back a confident wrong
     * answer, so a record that uses one is reported as unsupported instead of guessed at.
     */
    private static function rejectMacros(string $value, string $term, string $record): void
    {
        if (str_contains($value, '%')) {
            throw EvaluationFailure::permanent(sprintf('The term "%s" uses a macro, which msgpit does not expand.', $term), $record);
        }
    }
}
