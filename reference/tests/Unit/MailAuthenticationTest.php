<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Resolver;
use Msgpit\Mail\Report\Blocklists;
use Msgpit\Mail\Report\Context;
use Msgpit\Mail\Report\Dmarc;
use Msgpit\Mail\Report\Finding;
use Msgpit\Mail\Report\Origin;
use Msgpit\Mail\Report\Report;
use Msgpit\Mail\Report\Status;
use Msgpit\Mime\Parser;
use PHPUnit\Framework\TestCase;

/** The checks that need DNS: which address sent the message, and what the sender's zone says. */
final class MailAuthenticationTest extends TestCase
{
    /**
     * @param array<string, list<string>> $txt
     * @param array<string, list<string>> $addresses
     * @param array<string, string> $reverse
     */
    private function resolver(array $txt = [], array $addresses = [], array $reverse = [], bool $enabled = true): Resolver
    {
        return new class ($txt, $addresses, $reverse, $enabled) implements Resolver {
            private int $lookups = 0;

            /**
             * @param array<string, list<string>> $txt
             * @param array<string, list<string>> $addresses
             * @param array<string, string> $reverse
             */
            public function __construct(
                private readonly array $txt,
                private readonly array $addresses,
                private readonly array $reverse,
                private readonly bool $enabled,
            ) {}

            public function enabled(): bool
            {
                return $this->enabled;
            }

            public function lookups(): int
            {
                return $this->lookups;
            }

            /** @return list<string> */
            public function txt(string $name): array
            {
                $this->lookups++;

                return $this->txt[strtolower($name)] ?? [];
            }

            /** @return list<string> */
            public function addresses(string $name): array
            {
                $this->lookups++;

                return $this->addresses[strtolower($name)] ?? [];
            }

            /** @return list<string> */
            public function mx(string $name): array
            {
                $this->lookups++;

                return [];
            }

            public function reverse(string $ip): ?string
            {
                $this->lookups++;

                return $this->reverse[$ip] ?? null;
            }
        };
    }

    /** @param array<string, string> $headers */
    private function context(array $headers, ?Resolver $dns = null): Context
    {
        $headers += ['From' => 'Afzender <info@example.test>', 'Subject' => 'Onderwerp'];
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return new Context(Parser::parse(implode("\r\n", $lines) . "\r\n\r\nTekst."), null, 'Tekst.', null, false, $dns);
    }

    private function finding(Context $context, string $id): Finding
    {
        foreach (Report::build($context, Report::withNetwork())->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        self::fail("No finding with id {$id}");
    }

    /**
     * Received headers are prepended, so the top is the last hop. The private addresses above are
     * the receiving server talking to itself; the first public one handed the message over.
     */
    public function testTheSendingAddressIsTheFirstPublicOneInTheChain(): void
    {
        $raw = implode("\r\n", [
            'Received: by mail.example.test (Postfix, from userid 998) id A40A8; Mon, 14 Sep 2026 09:27:39 +0000',
            'Received: from localhost (localhost [127.0.0.1]) by mail.example.test',
            'Received: from mail-ej2.google.com (mail-ej2.google.com [74.125.228.140]) by mail.example.test',
            'Received: by mail-ej2.google.com with SMTP id abc',
            'From: a@example.test',
            '',
            'Tekst.',
        ]);

        $origin = Origin::fromRaw($raw);

        self::assertSame('74.125.228.140', $origin->ip);
        self::assertSame('mail-ej2.google.com', $origin->helo);
    }

    public function testReceivedSpfWinsOverTheChain(): void
    {
        $raw = "Received-SPF: Pass (mailfrom) client-ip=93.184.216.34; helo=mail.example.test;\r\n"
            . "Received: from other.test (other.test [74.125.228.140]) by mail.example.test\r\n\r\nTekst.";

        self::assertSame('93.184.216.34', Origin::fromRaw($raw)->ip);
    }

    /** A folded Received header carries the address on a continuation line as often as not. */
    public function testAFoldedReceivedHeaderIsStillRead(): void
    {
        $raw = "Received: from mail.example.test\r\n\t(mail.example.test [93.184.216.34])\r\n\tby mx.test\r\n\r\nTekst.";

        self::assertSame('93.184.216.34', Origin::fromRaw($raw)->ip);
    }

    public function testAMessageThatNeverTravelledHasNoOrigin(): void
    {
        self::assertFalse(Origin::fromRaw("From: a@example.test\r\n\r\nTekst.")->known());
    }

    public function testSpfIsEvaluatedAgainstTheSendingAddress(): void
    {
        $dns = $this->resolver(['example.test' => ['v=spf1 ip4:93.184.216.0/24 -all']]);
        $context = $this->context([
            'Received' => 'from mail.example.test (mail.example.test [93.184.216.34]) by mx.test',
        ], $dns);

        self::assertSame(Status::Pass, $this->finding($context, 'spf')->status);
    }

    public function testAnAddressOutsideTheRecordFails(): void
    {
        $dns = $this->resolver(['example.test' => ['v=spf1 ip4:93.184.216.0/24 -all']]);
        $context = $this->context([
            'Received' => 'from elders.test (elders.test [198.51.100.7]) by mx.test',
        ], $dns);
        $finding = $this->finding($context, 'spf');

        self::assertSame(Status::Fail, $finding->status);
        self::assertGreaterThan(0.0, $finding->penalty);
    }

    /**
     * Without a sending address there is nothing to evaluate, but the record itself still says
     * whether the domain is set up, and that is the half you control from here.
     */
    public function testWithoutASendingAddressTheRecordIsReportedRatherThanEvaluated(): void
    {
        $dns = $this->resolver(['example.test' => ['v=spf1 include:_spf.google.com -all']]);
        $finding = $this->finding($this->context([], $dns), 'spf');

        self::assertSame(Status::Pass, $finding->status);
        self::assertStringContainsString('publishes an SPF record', $finding->title);
        self::assertStringContainsString('rather than evaluated', $finding->explanation);
    }

    public function testADomainWithoutSpfIsMarkedDown(): void
    {
        self::assertSame(Status::Fail, $this->finding($this->context([], $this->resolver()), 'spf')->status);
    }

    public function testDmarcIsFoundAtTheParentDomain(): void
    {
        $dns = $this->resolver(['_dmarc.example.test' => ['v=DMARC1; p=reject; adkim=s']]);
        $context = $this->context(['From' => 'a@mail.example.test'], $dns);
        $finding = $this->finding($context, 'dmarc');

        self::assertSame(Status::Pass, $finding->status);
        self::assertStringContainsString('p=reject', $finding->title);
    }

    public function testAPolicyOfNoneIsARecordWithoutTeeth(): void
    {
        $dns = $this->resolver(['_dmarc.example.test' => ['v=DMARC1; p=none']]);

        self::assertSame(Status::Warn, $this->finding($this->context([], $dns), 'dmarc')->status);
    }

    public function testAMissingDmarcRecordIsAFailure(): void
    {
        self::assertSame(Status::Fail, $this->finding($this->context([], $this->resolver()), 'dmarc')->status);
    }

    /** Walking up must stop while two labels are left, or it would ask about the public suffix. */
    public function testTheWalkUpStopsAtTwoLabels(): void
    {
        $dns = $this->resolver(['_dmarc.test' => ['v=DMARC1; p=reject']]);

        self::assertNull(Dmarc::lookup($dns, 'mail.example.test'));
    }

    public function testSubdomainPolicyDefaultsToTheMainOne(): void
    {
        $dns = $this->resolver(['_dmarc.example.test' => ['v=DMARC1; p=quarantine']]);
        $dmarc = Dmarc::lookup($dns, 'example.test');

        self::assertNotNull($dmarc);
        self::assertSame('quarantine', $dmarc->subdomainPolicy);
        self::assertSame(100, $dmarc->percentage);
    }

    public function testRelaxedAlignmentAcceptsASubdomainAndStrictDoesNot(): void
    {
        $dns = $this->resolver(['_dmarc.example.test' => ['v=DMARC1; p=none']]);
        $dmarc = Dmarc::lookup($dns, 'example.test');

        self::assertNotNull($dmarc);
        self::assertTrue($dmarc->aligns('mail.example.test', 'example.test', 'r'));
        self::assertFalse($dmarc->aligns('mail.example.test', 'example.test', 's'));
        self::assertTrue($dmarc->aligns('example.test', 'example.test', 's'));
    }

    public function testReverseDnsIsConfirmedInBothDirections(): void
    {
        $dns = $this->resolver(
            addresses: ['mail.example.test' => ['93.184.216.34']],
            reverse: ['93.184.216.34' => 'mail.example.test'],
        );
        $context = $this->context(['Received' => 'from x (x [93.184.216.34]) by mx.test'], $dns);

        self::assertSame(Status::Pass, $this->finding($context, 'reverse-dns')->status);
    }

    public function testANameThatDoesNotPointBackIsFlagged(): void
    {
        $dns = $this->resolver(
            addresses: ['mail.example.test' => ['198.51.100.7']],
            reverse: ['93.184.216.34' => 'mail.example.test'],
        );
        $context = $this->context(['Received' => 'from x (x [93.184.216.34]) by mx.test'], $dns);

        self::assertSame(Status::Warn, $this->finding($context, 'reverse-dns')->status);
    }

    public function testAnAddressWithoutReverseDnsFails(): void
    {
        $context = $this->context(['Received' => 'from x (x [93.184.216.34]) by mx.test'], $this->resolver());

        self::assertSame(Status::Fail, $this->finding($context, 'reverse-dns')->status);
    }

    /** Offline is a supported way to work, and every network check has to say so rather than fail. */
    public function testEveryNetworkCheckSkipsWhenDnsIsOff(): void
    {
        $context = $this->context([], $this->resolver(enabled: false));

        foreach (['spf', 'dmarc', 'reverse-dns', 'dkim'] as $id) {
            self::assertSame(Status::Skip, $this->finding($context, $id)->status, $id);
        }
    }

    public function testASignatureAlreadyJudgedIsNotVerifiedAgain(): void
    {
        $context = $this->context([
            'DKIM-Signature' => 'v=1; a=rsa-sha256; d=example.test; s=sel; h=from; bh=x; b=y',
            'Authentication-Results' => 'mx.test; dkim=pass header.d=example.test',
        ], $this->resolver());
        $finding = $this->finding($context, 'dkim');

        self::assertSame(Status::Skip, $finding->status);
        self::assertStringContainsString('already judged', $finding->explanation);
    }

    public function testAMessageWithoutASignatureIsFlagged(): void
    {
        $finding = $this->finding($this->context([], $this->resolver()), 'dkim');

        self::assertSame(Status::Warn, $finding->status);
        self::assertStringContainsString('no DKIM signature', $finding->title);
    }

    /** @param array<string, list<string>> $listings */
    private function blocklistFinding(array $listings): Finding
    {
        $context = $this->context(
            ['Received' => 'from x (x [93.184.216.34]) by mx.test'],
            $this->resolver(addresses: $listings),
        );

        return $this->finding($context, 'blocklists');
    }

    public function testACleanAddressPassesEveryList(): void
    {
        $finding = $this->blocklistFinding([]);

        self::assertSame(Status::Pass, $finding->status);
        self::assertStringContainsString((string) Blocklists::count(), $finding->title);
    }

    public function testAListedAddressFails(): void
    {
        $finding = $this->blocklistFinding(['34.216.184.93.zen.spamhaus.org' => ['127.0.0.2']]);

        self::assertSame(Status::Fail, $finding->status);
        self::assertStringContainsString('Spamhaus', $finding->title);
        self::assertStringContainsString('Listed in Spamhaus (127.0.0.2)', $finding->evidence[0], 'The worst result comes first');
    }

    /**
     * The policy block list says "this address should not send mail directly", which is true of
     * every home connection and says nothing about the message.
     */
    public function testAPolicyListingIsNotAnAccusation(): void
    {
        $finding = $this->blocklistFinding(['34.216.184.93.zen.spamhaus.org' => ['127.0.0.10']]);

        self::assertSame(Status::Warn, $finding->status);
        self::assertStringContainsString('policy list', $finding->title);
    }

    /** The same code means the opposite on a list that answers for good addresses. */
    public function testAWhitelistingIsNotAListing(): void
    {
        $finding = $this->blocklistFinding(['34.216.184.93.hostkarma.junkemailfilter.com' => ['127.0.0.1']]);

        self::assertSame(Status::Pass, $finding->status);
    }

    public function testHostkarmaYellowIsCaution(): void
    {
        $finding = $this->blocklistFinding(['34.216.184.93.hostkarma.junkemailfilter.com' => ['127.0.0.3']]);

        self::assertSame(Status::Warn, $finding->status);
    }

    /** Lists answer in this range to refuse a query, and reading that as a listing is backwards. */
    public function testARefusedQueryIsNotAListing(): void
    {
        $finding = $this->blocklistFinding(['34.216.184.93.zen.spamhaus.org' => ['127.255.255.252']]);

        self::assertSame(Status::Pass, $finding->status);
        self::assertStringContainsString('declined to answer', $finding->explanation);
    }

    public function testEveryListRefusingIsNotAVerdict(): void
    {
        $listings = [];

        foreach ((new Blocklists($this->resolver()))->check('93.184.216.34') as $result) {
            $listings["34.216.184.93.{$result->zone}"] = ['127.255.255.252'];
        }

        self::assertSame(Status::Skip, $this->blocklistFinding($listings)->status);
    }

    public function testAnIpv6SenderIsNotGuessedAt(): void
    {
        $context = $this->context(
            ['Received' => 'from x (x [2001:db8::1]) by mx.test'],
            $this->resolver(),
        );
        $finding = $this->finding($context, 'blocklists');

        self::assertSame(Status::Skip, $finding->status);
        self::assertStringContainsString('IPv4 only', $finding->explanation);
    }
}
