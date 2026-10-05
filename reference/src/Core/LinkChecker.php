<?php

declare(strict_types=1);

namespace Msgpit\Core;

/**
 * Checks whether the links in a message actually lead anywhere.
 *
 * This is the one thing msgpit does that reaches outside the development network, and it only
 * ever happens when someone presses the button. That is not caution for its own sake: links in
 * mail carry one-shot tokens. Fetching a password reset or an unsubscribe link can spend it, and
 * a tracking pixel counts the fetch as somebody reading the mail. So it is never automatic, never
 * on capture, and never on opening a message.
 *
 * HEAD is used where the server allows it, which asks for the headers without the body.
 */
final readonly class LinkChecker
{
    public function __construct(
        private int $timeout = 8,
        private int $maxLinks = 50,
    ) {}

    /**
     * @param list<array{url: string, kind: string}> $links
     * @return list<array{url: string, kind: string, status: ?int, reason: ?string, redirect: ?string}>
     */
    public function check(array $links): array
    {
        $results = [];

        foreach (array_slice($links, 0, $this->maxLinks) as $link) {
            $results[] = $link + $this->probe($link['url']);
        }

        return $results;
    }

    /**
     * Ranges a message has no business pointing at, checked numerically rather than by name.
     *
     * This is where cloud metadata services live, and they hand out credentials to whatever asks.
     * Everything else on the private network stays reachable on purpose: checking that a template
     * built the right url for http://web is one of the reasons this exists.
     */
    private const REFUSED_RANGES = [
        ['169.254.0.0', 16],   // IPv4 link-local, including 169.254.169.254
        ['fe80::', 10],        // IPv6 link-local
        ['fd00:ec2::', 64],    // AWS IMDS over IPv6
    ];

    /** @return array{status: ?int, reason: ?string, redirect: ?string} */
    private function probe(string $url): array
    {
        $refusal = $this->refuse($url);

        if ($refusal !== null) {
            return ['status' => null, 'reason' => $refusal, 'redirect' => null];
        }

        $result = $this->request($url, 'HEAD');

        // Plenty of servers refuse HEAD but answer GET perfectly well.
        if (in_array($result['status'], [405, 501], true) || $result['status'] === null) {
            $fallback = $this->request($url, 'GET');

            if ($fallback['status'] !== null) {
                return $fallback;
            }
        }

        return $result;
    }

    /** @return string|null The reason to refuse, or null when the url may be fetched. */
    private function refuse(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Refused: only http and https are fetched';
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return 'Refused: no host';
        }

        // A trailing dot is the same host to a resolver, and brackets belong to the url syntax.
        $host = strtolower(rtrim(trim($host, '[]'), '.'));

        foreach ($this->addressesOf($host) as $address) {
            if (self::isRefusedAddress($address)) {
                return 'Refused: link-local address';
            }
        }

        return null;
    }

    /**
     * Where the host actually leads. A name is only a name: evil.example.com resolving to
     * 169.254.169.254 is the whole trick, so the decision has to be made on the address.
     *
     * @return list<string>
     */
    private function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        foreach (gethostbynamel($host) ?: [] as $address) {
            $addresses[] = $address;
        }

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    private static function isRefusedAddress(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        // An IPv4-mapped IPv6 address is that IPv4 address wearing a hat.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }

        foreach (self::REFUSED_RANGES as [$network, $bits]) {
            $base = @inet_pton($network);

            if ($base === false || strlen($base) !== strlen($packed)) {
                continue;
            }

            $bytes = intdiv($bits, 8);
            $remainder = $bits % 8;

            if (substr($packed, 0, $bytes) !== substr($base, 0, $bytes)) {
                continue;
            }

            if ($remainder === 0) {
                return true;
            }

            $mask = 0xFF << (8 - $remainder) & 0xFF;

            if ((ord($packed[$bytes]) & $mask) === (ord($base[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{status: ?int, reason: ?string, redirect: ?string} */
    private function request(string $url, string $method): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                // Reported rather than followed: a redirect chain is something you want to see.
                'follow_location' => 0,
                'header' => "User-Agent: msgpit link check\r\nAccept: */*\r\n",
            ],
            'ssl' => [
                // A development machine rarely trusts every certificate it meets, and a
                // certificate problem is not what this check is about.
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $handle = @fopen($url, 'r', false, $context);

        if ($handle === false) {
            return ['status' => null, 'reason' => 'Could not connect', 'redirect' => null];
        }

        /** @var array<string, mixed> $meta */
        $meta = stream_get_meta_data($handle);
        fclose($handle);

        /** @var list<string> $headers */
        $headers = is_array($meta['wrapper_data'] ?? null) ? array_values(array_filter($meta['wrapper_data'], 'is_string')) : [];

        return [
            'status' => self::status($headers),
            'reason' => null,
            'redirect' => self::header($headers, 'location'),
        ];
    }

    /** @param list<string> $headers */
    private static function status(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                // The last status line wins, in case the stream followed something after all.
                $status = (int) $matches[1];
            }
        }

        return $status ?? null;
    }

    /** @param list<string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
