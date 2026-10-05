<?php

declare(strict_types=1);

namespace Msgpit\Mail\Dkim;

/**
 * A parsed DKIM-Signature header. Parsing checks the grammar and the required tags only; whether
 * we can actually verify this algorithm or canonicalization is the verifier's judgement, so a
 * signature we do not support still carries its domain and selector into the result.
 */
final readonly class Signature
{
    private const REQUIRED = ['v', 'a', 'd', 's', 'h', 'bh', 'b'];

    /** @param list<string> $signedHeaders */
    private function __construct(
        public string $rawHeader,
        public string $version,
        public string $algorithm,
        public string $canonicalization,
        public string $domain,
        public string $selector,
        public array $signedHeaders,
        public string $bodyHash,
        public string $signature,
        public string $query,
        public ?int $timestamp,
        public ?int $expiration,
        public ?int $bodyLength,
    ) {}

    public static function parse(string $rawHeader): ?self
    {
        $colon = strpos($rawHeader, ':');

        if ($colon === false) {
            return null;
        }

        $tags = TagList::parse(substr($rawHeader, $colon + 1));

        if ($tags === null) {
            return null;
        }

        foreach (self::REQUIRED as $required) {
            if (($tags[$required] ?? '') === '') {
                return null;
            }
        }

        foreach (['t', 'x', 'l'] as $numeric) {
            if (isset($tags[$numeric]) && !ctype_digit($tags[$numeric])) {
                return null;
            }
        }

        $signed = array_values(array_filter(explode(':', strtolower(TagList::compact($tags['h'])))));

        return new self(
            rawHeader: $rawHeader,
            version: $tags['v'],
            algorithm: strtolower($tags['a']),
            canonicalization: strtolower(TagList::compact($tags['c'] ?? 'simple/simple')),
            domain: rtrim(strtolower($tags['d']), '.'),
            selector: strtolower($tags['s']),
            signedHeaders: $signed,
            bodyHash: TagList::compact($tags['bh']),
            signature: TagList::compact($tags['b']),
            query: strtolower(TagList::compact($tags['q'] ?? '')),
            timestamp: isset($tags['t']) ? (int) $tags['t'] : null,
            expiration: isset($tags['x']) ? (int) $tags['x'] : null,
            bodyLength: isset($tags['l']) ? (int) $tags['l'] : null,
        );
    }

    public function headerCanonicalization(): ?Canonicalization
    {
        return Canonicalization::tryFrom(explode('/', $this->canonicalization)[0]);
    }

    public function bodyCanonicalization(): ?Canonicalization
    {
        // A c= tag may name only the header algorithm; the body then falls back to simple.
        return Canonicalization::tryFrom(explode('/', $this->canonicalization)[1] ?? 'simple');
    }

    /** Only dns/txt exists, but a signature may ask for something we cannot answer. */
    public function usesDnsQuery(): bool
    {
        if ($this->query === '') {
            return true;
        }

        foreach (explode(':', $this->query) as $method) {
            if ($method === 'dns/txt' || str_starts_with($method, 'dns/txt/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The header as it goes into the hash: everything byte for byte, except the b= value, which
     * is cut out rather than rewritten so simple canonicalization still sees the original bytes.
     */
    public function headerWithoutSignature(): string
    {
        $colon = strpos($this->rawHeader, ':');

        if ($colon === false) {
            return $this->rawHeader;
        }

        $value = substr($this->rawHeader, $colon + 1);

        if (preg_match('/(?:^|;)[ \t\r\n]*b[ \t\r\n]*=/', $value, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $this->rawHeader;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $end = strpos($value, ';', $start);
        $length = ($end === false ? strlen($value) : $end) - $start;

        return substr($this->rawHeader, 0, $colon + 1) . substr_replace($value, '', $start, $length);
    }
}
