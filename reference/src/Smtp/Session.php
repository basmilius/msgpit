<?php

declare(strict_types=1);

namespace Msgpit\Smtp;

/**
 * The SMTP conversation for one connection, with no sockets in sight: feed it a line, get the
 * reply to write back. That keeps the protocol testable without opening a port.
 *
 * We advertise neither STARTTLS nor AUTH. Clients only use what the server offers, and every
 * client that matters here (msmtp, Symfony Mailer, Laravel) is happy to talk plain when nothing
 * else is on the table. Since this only ever listens inside a development network, encrypting
 * would buy nothing and cost a certificate.
 */
final class Session
{
    private const MAX_MESSAGE_BYTES = 26_214_400;

    private ?string $sender = null;

    /** @var list<string> */
    private array $recipients = [];

    private bool $receivingData = false;

    private string $data = '';

    private bool $tooLarge = false;

    /** @var list<Envelope> */
    private array $delivered = [];

    public function __construct(private readonly string $hostname = 'msgpit') {}

    public function greeting(): string
    {
        return "220 {$this->hostname} ESMTP msgpit";
    }

    /**
     * @return list<string> The lines to write back. Empty means: say nothing, keep listening.
     */
    public function line(string $line): array
    {
        if ($this->receivingData) {
            return $this->data($line);
        }

        $trimmed = rtrim($line, "\r\n");
        $verb = strtoupper(strtok($trimmed, ' ') ?: '');
        $rest = trim(substr($trimmed, strlen($verb)));

        return match ($verb) {
            'EHLO' => $this->ehlo(),
            'HELO' => ["250 {$this->hostname}"],
            'MAIL' => $this->mail($rest),
            'RCPT' => $this->rcpt($rest),
            'DATA' => $this->beginData(),
            'RSET' => $this->reset(),
            'NOOP' => ['250 2.0.0 OK'],
            'VRFY' => ['252 2.5.2 Cannot verify, but will accept'],
            'QUIT' => ["221 2.0.0 {$this->hostname} closing connection"],
            '' => [],
            default => ["502 5.5.1 Command {$verb} not implemented"],
        };
    }

    public function shouldClose(string $line): bool
    {
        return !$this->receivingData && strtoupper(strtok(rtrim($line, "\r\n"), ' ') ?: '') === 'QUIT';
    }

    /** @return list<Envelope> Messages accepted since the last call, which clears them. */
    public function takeDelivered(): array
    {
        $delivered = $this->delivered;
        $this->delivered = [];

        return $delivered;
    }

    /** @return list<string> */
    private function ehlo(): array
    {
        return [
            "250-{$this->hostname} greets you",
            '250-SIZE ' . self::MAX_MESSAGE_BYTES,
            '250-8BITMIME',
            '250-PIPELINING',
            '250 SMTPUTF8',
        ];
    }

    /** @return list<string> */
    private function mail(string $rest): array
    {
        if (preg_match('/FROM:\s*<([^>]*)>/i', $rest, $matches) !== 1) {
            return ['501 5.5.4 Syntax: MAIL FROM:<address>'];
        }

        $this->sender = $matches[1];
        $this->recipients = [];

        return ['250 2.1.0 OK'];
    }

    /** @return list<string> */
    private function rcpt(string $rest): array
    {
        if ($this->sender === null) {
            return ['503 5.5.1 Need MAIL before RCPT'];
        }

        if (preg_match('/TO:\s*<([^>]*)>/i', $rest, $matches) !== 1) {
            return ['501 5.5.4 Syntax: RCPT TO:<address>'];
        }

        $this->recipients[] = $matches[1];

        return ['250 2.1.5 OK'];
    }

    /** @return list<string> */
    private function beginData(): array
    {
        if ($this->recipients === []) {
            return ['503 5.5.1 Need RCPT before DATA'];
        }

        $this->receivingData = true;
        $this->data = '';
        $this->tooLarge = false;

        return ['354 End data with <CR><LF>.<CR><LF>'];
    }

    /** @return list<string> */
    private function data(string $line): array
    {
        if (rtrim($line, "\r\n") === '.') {
            return $this->finishData();
        }

        if (strlen($this->data) + strlen($line) > self::MAX_MESSAGE_BYTES) {
            // Keep reading to the terminating dot, or the client never gets its answer.
            $this->tooLarge = true;

            return [];
        }

        // Dot-stuffing: a line the client doubled to protect it starts with an extra dot.
        $this->data .= str_starts_with($line, '..') ? substr($line, 1) : $line;

        return [];
    }

    /** @return list<string> */
    private function finishData(): array
    {
        $this->receivingData = false;

        if ($this->tooLarge) {
            $this->reset();

            return ['552 5.3.4 Message too large'];
        }

        $this->delivered[] = new Envelope(
            sender: (string) $this->sender,
            recipients: $this->recipients,
            data: $this->data,
        );

        $this->reset();

        return ['250 2.0.0 OK: message accepted'];
    }

    /** @return list<string> */
    private function reset(): array
    {
        $this->sender = null;
        $this->recipients = [];
        $this->data = '';
        $this->receivingData = false;
        $this->tooLarge = false;

        return ['250 2.0.0 OK'];
    }
}
