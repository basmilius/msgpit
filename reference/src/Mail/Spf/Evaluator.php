<?php

declare(strict_types=1);

namespace Msgpit\Mail\Spf;

/**
 * Answers the one question SPF asks: was this IP allowed to send for this domain (RFC 7208)?
 *
 * For msgpit the IP comes out of the Received headers of an imported message, so it is an
 * observation about a message someone else wrote rather than about a live SMTP session.
 *
 * Two things are deliberately not supported, both reported instead of guessed at: macros end the
 * evaluation in permerror, and ptr is skipped with a note. This class does no DNS itself; the
 * caller hands it a Resolver.
 */
final readonly class Evaluator
{
    private const MAX_MX_HOSTS = 10;

    public function __construct(private Resolver $resolver) {}

    public function evaluate(string $ip, string $domain): SpfResult
    {
        $client = Ip::parse($ip);

        if ($client === null) {
            return new SpfResult(SpfStatus::PermError, sprintf('"%s" is not an IP address.', $ip));
        }

        $state = new Evaluation();

        try {
            return $this->checkHost($client, $domain, $state);
        } catch (EvaluationFailure $failure) {
            return new SpfResult($failure->status, $failure->getMessage(), $failure->record, null, $state->lookups(), $state->notes());
        }
    }

    private function checkHost(string $client, string $domain, Evaluation $state): SpfResult
    {
        $record = $this->record($domain, $state);

        if ($record === null) {
            return new SpfResult(SpfStatus::None, sprintf('%s publishes no SPF record.', $domain), null, null, $state->lookups(), $state->notes());
        }

        foreach ($record->mechanisms as $mechanism) {
            if ($this->matches($client, $domain, $mechanism, $state)) {
                return new SpfResult(
                    status: $mechanism->qualifier->status(),
                    reason: sprintf('%s matches "%s" in the record of %s.', self::show($client), $mechanism->term, $domain),
                    record: $record->raw,
                    mechanism: $mechanism->term,
                    lookups: $state->lookups(),
                    notes: $state->notes(),
                );
            }
        }

        if ($record->redirect !== null) {
            return $this->redirect($client, $domain, $record, $record->redirect, $state);
        }

        return new SpfResult(
            status: SpfStatus::Neutral,
            reason: sprintf('No mechanism in the record of %s matches %s, and it has no all.', $domain, self::show($client)),
            record: $record->raw,
            lookups: $state->lookups(),
            notes: $state->notes(),
        );
    }

    /** A redirect replaces the whole evaluation, so its verdict is the verdict (RFC 7208 6.1). */
    private function redirect(string $client, string $domain, Record $record, string $target, Evaluation $state): SpfResult
    {
        $state->spend('redirect=' . $target);

        $result = $this->checkHost($client, $target, $state);

        if ($result->status === SpfStatus::None) {
            throw EvaluationFailure::permanent(sprintf('The redirect of %s points at %s, which publishes no SPF record.', $domain, $target), $record->raw);
        }

        return new SpfResult(
            status: $result->status,
            reason: sprintf('%s redirects to %s: %s', $domain, $target, lcfirst($result->reason)),
            record: $result->record,
            mechanism: $result->mechanism,
            lookups: $state->lookups(),
            notes: $state->notes(),
        );
    }

    private static function show(string $packed): string
    {
        return inet_ntop($packed) ?: 'the client address';
    }

    private function matches(string $client, string $domain, Mechanism $mechanism, Evaluation $state): bool
    {
        return match ($mechanism->name) {
            'all' => true,
            'ip4', 'ip6' => Ip::matches($client, (string) $mechanism->target, $mechanism->prefixFor($client)),
            'a' => $this->matchesAddresses($client, $mechanism->target ?? $domain, $mechanism, $state),
            'mx' => $this->matchesExchangers($client, $mechanism->target ?? $domain, $mechanism, $state),
            'exists' => $this->exists((string) $mechanism->target, $mechanism, $state),
            'include' => $this->includes($client, $mechanism, $state),
            default => $this->skipPtr($state),
        };
    }

    private function matchesAddresses(string $client, string $target, Mechanism $mechanism, Evaluation $state): bool
    {
        $state->spend($mechanism->term);
        $addresses = $this->addresses($target);

        if ($addresses === []) {
            $state->countVoid($mechanism->term);

            return false;
        }

        foreach ($addresses as $address) {
            $packed = Ip::parse($address);

            if ($packed !== null && Ip::matches($client, $packed, $mechanism->prefixFor($client))) {
                return true;
            }
        }

        return false;
    }

    private function matchesExchangers(string $client, string $target, Mechanism $mechanism, Evaluation $state): bool
    {
        $state->spend($mechanism->term);
        $hosts = $this->exchangers($target);

        if ($hosts === []) {
            $state->countVoid($mechanism->term);

            return false;
        }

        // The hosts behind an MX are resolved outside the ten lookup budget, so they get their own cap.
        if (count($hosts) > self::MAX_MX_HOSTS) {
            throw EvaluationFailure::permanent(sprintf('"%s" resolves to more than %d mail exchangers.', $mechanism->term, self::MAX_MX_HOSTS));
        }

        foreach ($hosts as $host) {
            foreach ($this->addresses($host) as $address) {
                $packed = Ip::parse($address);

                if ($packed !== null && Ip::matches($client, $packed, $mechanism->prefixFor($client))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function exists(string $target, Mechanism $mechanism, Evaluation $state): bool
    {
        $state->spend($mechanism->term);

        if ($this->addresses($target) !== []) {
            return true;
        }

        $state->countVoid($mechanism->term);

        return false;
    }

    /** An include asks a yes or no question: only a pass of the included record is a match. */
    private function includes(string $client, Mechanism $mechanism, Evaluation $state): bool
    {
        $state->spend($mechanism->term);

        $target = (string) $mechanism->target;
        $result = $this->checkHost($client, $target, $state);

        if ($result->status === SpfStatus::None) {
            throw EvaluationFailure::permanent(sprintf('"%s" points at a domain without an SPF record.', $mechanism->term));
        }

        return $result->status === SpfStatus::Pass;
    }

    private function skipPtr(Evaluation $state): bool
    {
        $state->note('The record uses ptr, which RFC 7208 deprecates; msgpit does not evaluate it.');

        return false;
    }

    private function record(string $domain, Evaluation $state): ?Record
    {
        $records = array_values(array_filter($this->txt($domain), Record::looksLikeSpf(...)));

        if (count($records) > 1) {
            throw EvaluationFailure::permanent(sprintf('%s publishes %d SPF records; exactly one is allowed.', $domain, count($records)));
        }

        if ($records === []) {
            return null;
        }

        return Record::parse($records[0]);
    }

    /** @return list<string> */
    private function txt(string $name): array
    {
        try {
            return $this->resolver->txt($name);
        } catch (ResolverFailure $failure) {
            throw EvaluationFailure::transient(sprintf('The TXT lookup for %s failed: %s', $name, $failure->getMessage()));
        }
    }

    /** @return list<string> */
    private function addresses(string $name): array
    {
        try {
            return $this->resolver->addresses($name);
        } catch (ResolverFailure $failure) {
            throw EvaluationFailure::transient(sprintf('The address lookup for %s failed: %s', $name, $failure->getMessage()));
        }
    }

    /** @return list<string> */
    private function exchangers(string $name): array
    {
        try {
            return $this->resolver->mx($name);
        } catch (ResolverFailure $failure) {
            throw EvaluationFailure::transient(sprintf('The MX lookup for %s failed: %s', $name, $failure->getMessage()));
        }
    }
}
