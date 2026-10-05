<?php

declare(strict_types=1);

namespace Msgpit\Core;

final class ProviderRegistry
{
    /** @var array<string, Provider> */
    private array $providers = [];

    /** @param list<Provider> $providers */
    public function __construct(array $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->id()] = $provider;
        }
    }

    /**
     * @param list<class-string> $classes
     * @param list<string> $enabled Empty means all.
     */
    public static function fromClasses(array $classes, array $enabled = []): self
    {
        $providers = [];

        foreach ($classes as $class) {
            $provider = new $class();

            if (!$provider instanceof Provider) {
                throw new \InvalidArgumentException("{$class} does not implement " . Provider::class);
            }

            if ($enabled === [] || in_array($provider->id(), $enabled, true)) {
                $providers[] = $provider;
            }
        }

        return new self($providers);
    }

    public function get(string $id): ?Provider
    {
        return $this->providers[$id] ?? null;
    }

    /**
     * Where an application should point its base url for this provider: our route prefix plus
     * whatever the provider's own routes have in common. Spryng puts everything under /v2, so it
     * ends up as /spryng/v2 without anyone having to write that down twice.
     */
    public static function baseUrl(Provider $provider): string
    {
        $paths = array_map(
            static fn (Route $route): string => $route->pattern,
            array_filter($provider->routes(), static fn (Route $route): bool => !str_contains($route->pattern, '{')),
        );

        return '/' . $provider->id() . self::commonPrefix(array_values($paths));
    }

    /**
     * The directories every route has in common. The last segment of a path is the endpoint
     * itself, so it is dropped before comparing rather than after: /v2/messages and /v2/balance
     * share /v2, not nothing.
     *
     * @param list<string> $paths
     */
    private static function commonPrefix(array $paths): string
    {
        if ($paths === []) {
            return '';
        }

        $directories = [];

        foreach ($paths as $path) {
            $segments = explode('/', trim($path, '/'));
            array_pop($segments);
            $directories[] = $segments;
        }

        $shared = $directories[0];

        foreach ($directories as $segments) {
            $common = [];

            foreach ($shared as $index => $segment) {
                if (($segments[$index] ?? null) !== $segment) {
                    break;
                }

                $common[] = $segment;
            }

            $shared = $common;
        }

        return $shared === [] ? '' : '/' . implode('/', $shared);
    }

    /** @return list<Provider> */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
