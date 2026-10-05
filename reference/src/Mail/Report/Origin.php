<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

/**
 * Where a message came from, as far as its own headers admit.
 *
 * SPF asks whether one IP was allowed to send for one domain, so everything depends on picking the
 * right IP. Received headers are prepended, so the top is the last hop; we walk down and take the
 * first public address, which is the machine that handed the message to the receiving server. The
 * private ones above it are that server talking to itself.
 *
 * Read from the raw message rather than the header map: a message has many Received headers and a
 * name to value map keeps one.
 */
final readonly class Origin
{
    public function __construct(
        public ?string $ip = null,
        public ?string $helo = null,
        public ?string $claimedHost = null,
    ) {}

    public function known(): bool
    {
        return $this->ip !== null;
    }

    public static function fromRaw(string $raw): self
    {
        $headers = str_replace("\r\n", "\n", explode("\n\n", $raw, 2)[0]);

        // Unfold: a Received header runs over several lines and the address can be on any of them.
        $unfolded = (string) preg_replace('/\n[ \t]+/', ' ', $headers);
        $lines = explode("\n", $unfolded);

        foreach ($lines as $line) {
            if (preg_match('/^received-spf:/i', $line) === 1 && preg_match('/client-ip=([^;\s]+)/i', $line, $match) === 1) {
                $ip = self::publicIp(trim($match[1]));

                if ($ip !== null) {
                    preg_match('/helo=([^;\s]+)/i', $line, $helo);

                    return new self($ip, $helo[1] ?? null);
                }
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/^received:/i', $line) !== 1) {
                continue;
            }

            preg_match_all('/\[?((?:\d{1,3}\.){3}\d{1,3}|[0-9a-f:]{6,})\]?/i', $line, $matches);

            foreach ($matches[1] as $candidate) {
                $ip = self::publicIp($candidate);

                if ($ip === null) {
                    continue;
                }

                preg_match('/^received:\s*from\s+([^\s(]+)/i', $line, $from);

                return new self($ip, $from[1] ?? null, $from[1] ?? null);
            }
        }

        return new self();
    }

    /** Anything a receiving server would have seen from the outside: not loopback, not a LAN. */
    private static function publicIp(string $candidate): ?string
    {
        $candidate = trim($candidate, '[]() \t');

        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $public = filter_var(
            $candidate,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        return is_string($public) ? $public : null;
    }
}
