<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

/**
 * The DNS an SPF evaluation needs, and nothing more. Implementations throw ResolverFailure for a
 * transient problem; a name that simply has no records answers with an empty list.
 */
interface Resolver
{
    /** @return list<string> The TXT records at this name, empty when there are none. */
    public function txt(string $name): array;

    /** @return list<string> The A and AAAA addresses at this name. */
    public function addresses(string $name): array;

    /** @return list<string> The mail exchangers at this name, by hostname. */
    public function mx(string $name): array;
}
