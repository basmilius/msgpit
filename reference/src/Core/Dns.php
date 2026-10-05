<?php

declare(strict_types=1);

namespace Msgpit\Core;


/**
 * The one place msgpit asks the network a question that is not an http request.
 *
 * DNS is how SPF, DMARC and DKIM can be judged at all: the answer lives in the sender's zone and
 * nowhere else. It is a second outbound path next to delivery-report callbacks and the link check,
 * so it is switchable with MSGPIT_DNS=off for anyone working offline.
 *
 * Answers are cached with their own TTL. The names come out of a captured message, so they are a
 * sender's to choose; callers that walk a chain of them (SPF) must cap how many they follow.
 */
final class Dns implements Resolver
{
    private const MIN_TTL = 60;

    private const MAX_TTL = 3600;

    /** A name that resolves to nothing is cached briefly: it is usually a record about to appear. */
    private const MISS_TTL = 60;

    private int $lookups = 0;

    public function __construct(
        private readonly ?Storage $storage = null,
        private readonly bool $enabled = true,
    ) {}

    public static function fromEnvironment(?Storage $storage = null): self
    {
        $setting = strtolower(trim((string) (getenv('MSGPIT_DNS') ?: '')));

        return new self($storage, !in_array($setting, ['off', '0', 'no', 'false'], true));
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** How many questions were asked, so a caller can hold itself to a limit. */
    public function lookups(): int
    {
        return $this->lookups;
    }

    /** @return list<string> */
    public function txt(string $name): array
    {
        return $this->query($name, DNS_TXT, static fn (array $record): ?string => is_string($record['txt'] ?? null) ? $record['txt'] : null);
    }

    /** @return list<string> */
    public function addresses(string $name): array
    {
        return $this->query($name, DNS_A | DNS_AAAA, static function (array $record): ?string {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            return is_string($address) ? $address : null;
        });
    }

    /** @return list<string> */
    public function mx(string $name): array
    {
        return $this->query($name, DNS_MX, static fn (array $record): ?string => is_string($record['target'] ?? null) ? $record['target'] : null);
    }

    /** Whether anything answers at this name, which is all a blocklist or an SPF exists: asks. */
    public function exists(string $name): bool
    {
        return $this->addresses($name) !== [];
    }

    public function reverse(string $ip): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $cached = $this->storage?->cached("dns:ptr:{$ip}");

        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }

        $this->lookups++;
        $host = gethostbyaddr($ip);
        $name = $host === false || $host === $ip ? null : $host;
        $this->storage?->cache("dns:ptr:{$ip}", $name ?? '', $name === null ? self::MISS_TTL : self::MAX_TTL);

        return $name;
    }

    /**
     * @param callable(array<string, mixed>): ?string $extract
     * @return list<string>
     */
    private function query(string $name, int $type, callable $extract): array
    {
        if (!$this->enabled || trim($name) === '') {
            return [];
        }

        $name = rtrim(strtolower(trim($name)), '.');
        $key = "dns:{$type}:{$name}";
        $cached = $this->storage?->cached($key);

        if ($cached !== null) {
            $decoded = json_decode($cached, true);

            if (is_array($decoded)) {
                /** @var list<string> $values */
                $values = array_values(array_filter($decoded, 'is_string'));

                return $values;
            }
        }

        $this->lookups++;
        $records = @dns_get_record($name, $type);
        $values = [];
        $ttl = self::MAX_TTL;

        foreach (is_array($records) ? $records : [] as $record) {
            /** @var array<string, mixed> $record */
            $value = $extract($record);

            if ($value !== null && $value !== '') {
                $values[] = $value;
                $ttl = min($ttl, is_int($record['ttl'] ?? null) ? $record['ttl'] : self::MAX_TTL);
            }
        }

        $this->storage?->cache(
            $key,
            (string) json_encode($values),
            $values === [] ? self::MISS_TTL : max(self::MIN_TTL, $ttl),
        );

        return $values;
    }
}
