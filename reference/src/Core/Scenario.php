<?php

declare(strict_types=1);

namespace Msgpit\Core;

enum Scenario: string
{
    case InvalidNumber = 'InvalidNumber';
    case Unauthorized = 'Unauthorized';
    case RateLimited = 'RateLimited';
    case ServerError = 'ServerError';

    /**
     * Magic recipients that trigger a scenario, defined here so providers stay unaware of them.
     *
     * @return array<string, self>
     */
    public static function magicNumbers(): array
    {
        return [
            '+31600000001' => self::InvalidNumber,
            '+31600000002' => self::Unauthorized,
            '+31600000003' => self::RateLimited,
            '+31600000004' => self::ServerError,
        ];
    }

    /** Shown in the UI and in the reference docs, so the two cannot drift apart. */
    public function description(): string
    {
        return match ($this) {
            self::InvalidNumber => 'The recipient is rejected as malformed or unroutable.',
            self::Unauthorized => 'Credentials are missing or rejected.',
            self::RateLimited => 'Too many requests; the provider asks you to back off.',
            self::ServerError => 'Something broke on the provider side.',
        };
    }

    public function recipient(): ?string
    {
        return array_search($this, self::magicNumbers(), true) ?: null;
    }

    public static function forRecipient(string $recipient): ?self
    {
        $digits = preg_replace('/\D/', '', $recipient) ?? '';

        // Apps send E.164 with a plus, but a 00 prefix is common enough to accept too.
        $normalized = '+' . (str_starts_with($digits, '00') ? substr($digits, 2) : $digits);

        return self::magicNumbers()[$normalized] ?? null;
    }
}
