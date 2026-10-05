<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

/**
 * A resolver that resolves nothing: it answers from tables it was handed. The real one queries DNS
 * and lives outside this namespace, so evaluation stays testable without a network.
 */
final readonly class StaticResolver implements Resolver
{
    /** @var array<string, list<string>> */
    private array $txt;

    /** @var array<string, list<string>> */
    private array $addresses;

    /** @var array<string, list<string>> */
    private array $mx;

    /** @var list<string> */
    private array $failures;

    /**
     * @param array<string, list<string>> $txt
     * @param array<string, list<string>> $addresses
     * @param array<string, list<string>> $mx
     * @param list<string> $failures Names that answer with a transient failure.
     */
    public function __construct(array $txt = [], array $addresses = [], array $mx = [], array $failures = [])
    {
        $this->txt = self::normalize($txt);
        $this->addresses = self::normalize($addresses);
        $this->mx = self::normalize($mx);
        $this->failures = array_map(self::key(...), $failures);
    }

    public function txt(string $name): array
    {
        return $this->lookup($this->txt, $name);
    }

    public function addresses(string $name): array
    {
        return $this->lookup($this->addresses, $name);
    }

    public function mx(string $name): array
    {
        return $this->lookup($this->mx, $name);
    }

    /**
     * @param array<string, list<string>> $table
     * @return list<string>
     */
    private function lookup(array $table, string $name): array
    {
        $key = self::key($name);

        if (in_array($key, $this->failures, true)) {
            throw new ResolverFailure(sprintf('The lookup for "%s" failed.', $name));
        }

        return $table[$key] ?? [];
    }

    /**
     * @param array<string, list<string>> $table
     * @return array<string, list<string>>
     */
    private static function normalize(array $table): array
    {
        $normalized = [];

        foreach ($table as $name => $records) {
            $normalized[self::key($name)] = $records;
        }

        return $normalized;
    }

    private static function key(string $name): string
    {
        return strtolower(rtrim($name, '.'));
    }
}
