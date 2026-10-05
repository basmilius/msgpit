<?php

declare(strict_types=1);

namespace Msgpit\Core;

/**
 * Asks a spamd for a verdict on a captured message.
 *
 * The protocol is a few lines over TCP: a REPORT request with the message, and a reply carrying
 * the score, the threshold and the rules that fired. Nothing is installed here; the daemon runs
 * beside us, the way Mailpit does it, so the container stays what it was.
 *
 * Scoring is best effort. A daemon that is down, slow or absent means no report, never a failed
 * capture: a development mail catcher that drops mail because a side service is unhappy is worse
 * than one that shows no score.
 */
final readonly class SpamAssassin
{
    public function __construct(
        private string $address,
        private int $timeout = 10,
    ) {}

    /** Configured with MSGPIT_SPAMASSASSIN, as host:port, the same spelling Mailpit uses. */
    public static function fromEnvironment(): ?self
    {
        $address = getenv('MSGPIT_SPAMASSASSIN');

        return is_string($address) && $address !== '' ? new self($address) : null;
    }

    public function check(string $message): ?SpamReport
    {
        [$host, $port] = self::split($this->address);

        $socket = @fsockopen($host, $port, $errno, $error, $this->timeout);

        if ($socket === false) {
            return null;
        }

        stream_set_timeout($socket, $this->timeout);

        // spamd counts bytes, and a mismatch is an error rather than a truncation.
        $body = str_replace("\r\n", "\n", $message);
        $body = str_replace("\n", "\r\n", $body);

        fwrite($socket, "REPORT SPAMC/1.5\r\nContent-length: " . strlen($body) . "\r\n\r\n" . $body);

        $response = '';

        while (!feof($socket)) {
            $chunk = fread($socket, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $response .= $chunk;
        }

        fclose($socket);

        return self::parse($response);
    }

    private static function parse(string $response): ?SpamReport
    {
        // "Spam: True ; 6.2 / 5.0"
        if (preg_match('/^Spam:\s*(True|False)\s*;\s*(-?[\d.]+)\s*\/\s*(-?[\d.]+)/mi', $response, $matches) !== 1) {
            return null;
        }

        return new SpamReport(
            spam: strtolower($matches[1]) === 'true',
            score: (float) $matches[2],
            threshold: (float) $matches[3],
            rules: self::rules($response),
            report: trim(self::body($response)),
        );
    }

    /**
     * The table at the end of the report: points, rule name, description. Reading it beats the
     * bare score, because it says which sentence in your template is the problem.
     *
     * @return list<array{points: float, name: string, description: string}>
     */
    private static function rules(string $response): array
    {
        $rules = [];
        $inTable = false;

        foreach (explode("\n", $response) as $line) {
            if (preg_match('/^\s*-+\s+-+\s+-+/', $line) === 1) {
                $inTable = true;

                continue;
            }

            if (!$inTable) {
                continue;
            }

            if (preg_match('/^\s*(-?[\d.]+)\s+(\S+)\s+(.*)$/', $line, $matches) === 1) {
                $rules[] = [
                    'points' => (float) $matches[1],
                    'name' => $matches[2],
                    'description' => trim($matches[3]),
                ];

                continue;
            }

            // A description wrapped onto the next line belongs to the rule above it.
            if ($rules !== [] && trim($line) !== '' && preg_match('/^\s{4,}\S/', $line) === 1) {
                $last = count($rules) - 1;
                $rules[$last] = [
                    'points' => $rules[$last]['points'],
                    'name' => $rules[$last]['name'],
                    'description' => $rules[$last]['description'] . ' ' . trim($line),
                ];
            }
        }

        return $rules;
    }

    private static function body(string $response): string
    {
        $position = strpos($response, "\r\n\r\n");

        return $position === false ? $response : substr($response, $position + 4);
    }

    /** @return array{0: string, 1: int} */
    private static function split(string $address): array
    {
        $position = strrpos($address, ':');

        if ($position === false) {
            return [$address, 783];
        }

        return [substr($address, 0, $position), (int) substr($address, $position + 1)];
    }
}
