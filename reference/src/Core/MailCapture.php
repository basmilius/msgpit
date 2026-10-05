<?php

declare(strict_types=1);

namespace Msgpit\Core;

use Msgpit\Mime\ParsedMessage;
use Msgpit\Mime\Parser;
use Msgpit\Smtp\Envelope;

/**
 * Turns an accepted SMTP envelope into stored messages.
 *
 * One message per recipient, as everywhere else in msgpit: a mail to three people is three rows
 * sharing a batchId. The envelope decides who they are, not the To header, because that is how
 * delivery actually works and it is the only way a Bcc shows up at all.
 */
final readonly class MailCapture
{
    public function __construct(
        private Storage $storage,
        private ?SpamAssassin $spamAssassin = null,
    ) {}

    /**
     * Takes in a .eml file, which has no envelope: nobody delivered it, it was dragged in from a
     * mail client. The recipients then come from the headers, which is the closest thing to who
     * the message was for, and the source is recorded so the difference stays visible.
     */
    public function import(string $raw, ?string $filename = null): int
    {
        $parsed = Parser::parse($raw);
        $recipients = [];

        foreach (['to', 'cc', 'bcc'] as $header) {
            foreach (ParsedMessage::addresses($parsed->headers[$header] ?? '') as $address) {
                $recipients[] = $address;
            }
        }

        // A message with no recipient at all still tells you something, so it is kept under a
        // placeholder rather than refused.
        $recipients = array_values(array_unique($recipients)) ?: ['(no recipient)'];

        $sender = $parsed->headers['return-path'] ?? $parsed->from;

        /*
         * One row, however many addresses are on it. Everywhere else in msgpit a request to three
         * people is three messages, because that is three deliveries and each can succeed or fail
         * on its own. An import is the opposite: one message that already arrived once, and three
         * identical rows in the list would be noise rather than information.
         *
         * The addresses are joined rather than dropped, so filtering by recipient still finds it:
         * that filter matches on a substring.
         */
        return $this->store(
            $parsed,
            new Envelope($sender, [implode(', ', $recipients)], $raw),
            ['imported' => true, 'filename' => $filename, 'recipients' => count($recipients)],
        );
    }

    public function capture(Envelope $envelope): void
    {
        $this->store(Parser::parse($envelope->data), $envelope);
    }

    /**
     * @param array<string, mixed> $extra
     * @return int the number of stored messages, one per recipient
     */
    private function store(ParsedMessage $parsed, Envelope $envelope, array $extra = []): int
    {
        $batchId = Uuid::v4();
        $messages = [];

        // Best effort: a spamd that is down must not cost us the message.
        $spam = $this->spamAssassin?->check($envelope->data);

        foreach ($envelope->recipients as $recipient) {
            $messages[] = Message::create(
                batchId: $batchId,
                provider: $extra === [] ? 'smtp' : 'import',
                channel: Channel::Email,
                to: $recipient,
                body: $parsed->preview(),
                from: $parsed->from !== '' ? $parsed->from : $envelope->sender,
                providerRef: $parsed->headers['message-id'] ?? null,
                meta: self::meta($parsed, $envelope, $spam, $extra),
            );
        }

        if ($messages === []) {
            return 0;
        }

        $this->storage->storeMail($messages, $parsed);

        return count($messages);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function meta(ParsedMessage $parsed, Envelope $envelope, ?SpamReport $spam, array $extra = []): array
    {
        // An import has no envelope: the addresses below were read off the headers, and reporting
        // them as envelope data would claim a delivery that never happened.
        $imported = $extra !== [];

        $meta = [
            'subject' => $parsed->subject,
            'envelopeSender' => $imported ? null : $envelope->sender,
            'envelopeRecipients' => $imported ? [] : $envelope->recipients,
            'to' => $parsed->headers['to'] ?? null,
            'cc' => $parsed->headers['cc'] ?? null,
            'replyTo' => $parsed->headers['reply-to'] ?? null,
            'date' => $parsed->headers['date'] ?? null,
            'hasHtml' => $parsed->html() !== null,
            'attachments' => count($parsed->attachments()),
            'spam' => $spam?->toArray(),
        ];

        return array_filter($meta + $extra, static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
