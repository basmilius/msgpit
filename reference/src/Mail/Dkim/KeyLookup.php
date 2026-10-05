<?php

declare(strict_types=1);

namespace Msgpit\Mail\Dkim;

interface KeyLookup
{
    /** The DKIM public key record at <selector>._domainkey.<domain>, or null when it is not published. */
    public function publicKey(string $selector, string $domain): ?string;
}
