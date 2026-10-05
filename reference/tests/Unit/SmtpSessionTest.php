<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Smtp\Envelope;
use Msgpit\Smtp\Session;
use PHPUnit\Framework\TestCase;

final class SmtpSessionTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session('msgpit.test');
    }

    /**
     * @param list<string> $lines
     * @return list<string> Every reply, in order.
     */
    private function say(array $lines): array
    {
        $replies = [];

        foreach ($lines as $line) {
            foreach ($this->session->line($line . "\r\n") as $reply) {
                $replies[] = $reply;
            }
        }

        return $replies;
    }

    /** @return list<string> */
    private function deliver(string $body, string $to = 'raymond@example.test'): array
    {
        return $this->say([
            'EHLO client.test',
            'MAIL FROM:<sender@example.test>',
            "RCPT TO:<{$to}>",
            'DATA',
            ...explode("\n", $body),
            '.',
        ]);
    }

    public function testTheGreetingNamesTheServer(): void
    {
        self::assertStringStartsWith('220 msgpit.test ESMTP', $this->session->greeting());
    }

    public function testEhloAnnouncesCapabilitiesWithTheLastLineUnhyphenated(): void
    {
        $replies = $this->say(['EHLO client.test']);

        self::assertGreaterThan(1, count($replies));

        foreach (array_slice($replies, 0, -1) as $line) {
            self::assertStringStartsWith('250-', $line, 'Continuation lines use a hyphen');
        }

        $last = end($replies);

        self::assertIsString($last);
        self::assertStringStartsWith('250 ', $last, 'The final line ends the list');
    }

    /** Offering these would make clients ask for them, and then we would have to mean it. */
    public function testItOffersNeitherStarttlsNorAuth(): void
    {
        $announced = implode(' ', $this->say(['EHLO client.test']));

        self::assertStringNotContainsString('STARTTLS', $announced);
        self::assertStringNotContainsString('AUTH', $announced);
    }

    public function testHeloIsAnsweredToo(): void
    {
        self::assertSame(['250 msgpit.test'], $this->say(['HELO client.test']));
    }

    public function testAFullDialogueDeliversAMessage(): void
    {
        $replies = $this->deliver("Subject: Hallo\n\nDit is de tekst.");

        self::assertStringStartsWith('354 ', $replies[count($replies) - 2]);
        $last = end($replies);

        self::assertIsString($last);
        self::assertStringStartsWith('250 2.0.0 OK', $last);

        $delivered = $this->session->takeDelivered();

        self::assertCount(1, $delivered);
        self::assertSame('sender@example.test', $delivered[0]->sender);
        self::assertSame(['raymond@example.test'], $delivered[0]->recipients);
        self::assertStringContainsString('Dit is de tekst.', $delivered[0]->data);
    }

    public function testTakingDeliveredMessagesClearsThem(): void
    {
        $this->deliver("Subject: Een\n\nTekst.");

        self::assertCount(1, $this->session->takeDelivered());
        self::assertSame([], $this->session->takeDelivered());
    }

    /** One message to three recipients is one envelope; who it is stored for is decided later. */
    public function testEveryRecipientIsKept(): void
    {
        $this->say([
            'EHLO client.test',
            'MAIL FROM:<sender@example.test>',
            'RCPT TO:<een@example.test>',
            'RCPT TO:<twee@example.test>',
            'RCPT TO:<drie@example.test>',
            'DATA',
            'Subject: Batch',
            '',
            'Tekst.',
            '.',
        ]);

        $delivered = $this->session->takeDelivered();

        self::assertCount(1, $delivered);
        self::assertSame(['een@example.test', 'twee@example.test', 'drie@example.test'], $delivered[0]->recipients);
    }

    /**
     * A line that begins with a dot is doubled by the sender so it cannot be mistaken for the
     * terminator. Failing to undo that quietly corrupts the message body.
     */
    public function testDotStuffingIsUndone(): void
    {
        $this->deliver("Subject: Punten\n\n..dit begon met een punt\ngewone regel");

        $data = $this->session->takeDelivered()[0]->data;

        self::assertStringContainsString(".dit begon met een punt", $data);
        self::assertStringNotContainsString("..dit", $data);
    }

    public function testALoneDotEndsTheMessageAndIsNotPartOfIt(): void
    {
        $this->deliver("Subject: Einde\n\nLaatste regel");

        self::assertStringNotContainsString("\n.\n", $this->session->takeDelivered()[0]->data);
    }

    public function testTwoMessagesOverOneConnection(): void
    {
        $this->deliver("Subject: Een\n\nEerste.", 'een@example.test');
        $this->deliver("Subject: Twee\n\nTweede.", 'twee@example.test');

        $delivered = $this->session->takeDelivered();

        self::assertCount(2, $delivered);
        self::assertSame(['een@example.test'], $delivered[0]->recipients);
        self::assertSame(['twee@example.test'], $delivered[1]->recipients);
    }

    public function testRsetForgetsTheEnvelope(): void
    {
        $this->say(['MAIL FROM:<sender@example.test>', 'RCPT TO:<a@example.test>', 'RSET']);

        self::assertSame(['503 5.5.1 Need RCPT before DATA'], $this->say(['DATA']));
    }

    public function testDataWithoutRecipientsIsRefused(): void
    {
        $this->say(['MAIL FROM:<sender@example.test>']);

        self::assertSame(['503 5.5.1 Need RCPT before DATA'], $this->say(['DATA']));
    }

    public function testRecipientWithoutSenderIsRefused(): void
    {
        self::assertSame(['503 5.5.1 Need MAIL before RCPT'], $this->say(['RCPT TO:<a@example.test>']));
    }

    public function testMalformedCommandsGetASyntaxError(): void
    {
        self::assertStringStartsWith('501 ', $this->say(['MAIL FROM: sender@example.test'])[0]);
        $this->say(['MAIL FROM:<a@example.test>']);
        self::assertStringStartsWith('501 ', $this->say(['RCPT TO: nonsense'])[0]);
    }

    public function testAnUnknownCommandIsRefusedButTheSessionSurvives(): void
    {
        self::assertStringStartsWith('502 ', $this->say(['STARTTLS'])[0]);
        self::assertSame(['250 msgpit.test'], $this->say(['HELO client.test']));
    }

    public function testAnEmptyLineOutsideDataIsIgnored(): void
    {
        self::assertSame([], $this->say(['']));
    }

    public function testNoopAndVrfy(): void
    {
        self::assertSame(['250 2.0.0 OK'], $this->say(['NOOP']));
        self::assertStringStartsWith('252 ', $this->say(['VRFY someone'])[0]);
    }

    public function testQuitSaysGoodbyeAndClosesTheConnection(): void
    {
        self::assertStringStartsWith('221 ', $this->say(['QUIT'])[0]);
        self::assertTrue($this->session->shouldClose("QUIT\r\n"));
        self::assertFalse($this->session->shouldClose("NOOP\r\n"));
    }

    /** A "QUIT" inside a message body is text, not a command. */
    public function testQuitInsideDataDoesNotCloseTheConnection(): void
    {
        $this->say(['MAIL FROM:<a@example.test>', 'RCPT TO:<b@example.test>', 'DATA']);

        self::assertFalse($this->session->shouldClose("QUIT\r\n"));
    }

    public function testAnEnvelopeIsAValueObject(): void
    {
        $this->deliver("Subject: Test\n\nTekst.");
        $envelope = $this->session->takeDelivered()[0];

        self::assertInstanceOf(Envelope::class, $envelope);
        self::assertNotSame('', $envelope->data);
    }

    public function testABounceAddressMayBeEmpty(): void
    {
        $replies = $this->say(['MAIL FROM:<>', 'RCPT TO:<a@example.test>']);

        self::assertSame(['250 2.1.0 OK', '250 2.1.5 OK'], $replies);
    }
}
