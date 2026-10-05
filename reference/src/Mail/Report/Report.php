<?php

declare(strict_types=1);

namespace Msgpit\Mail\Report;

/**
 * What a receiving mail server is likely to hold against this message, as far as that can be told
 * from the message itself.
 *
 * Scored out of ten like the tools people already know, with one rule of our own: a check that
 * does not apply is left out of the sum instead of counted against you. A mail we caught locally
 * never travelled, so it has no sending server, no SPF and no signature, and marking every test
 * mail down for that would turn the number into noise.
 *
 * It is not a prediction. Real filters weigh reputation and history that no local tool can see.
 */
final readonly class Report
{
    private const MAX_SCORE = 10.0;

    /** @var list<class-string<Check>> */
    public const CHECKS = [
        Checks\SpamScore::class,
        Checks\Authentication::class,
        Checks\TextAndHtml::class,
        Checks\DangerousHtml::class,
        Checks\ImageAlt::class,
        Checks\GmailClipping::class,
        Checks\HtmlSupport::class,
        Checks\Charset::class,
        Checks\Attachments::class,
        Checks\RequiredHeaders::class,
        Checks\SenderName::class,
        Checks\ReturnPath::class,
        Checks\Unsubscribe::class,
        Checks\LinkText::class,
        Checks\UrlShorteners::class,
    ];

    /**
     * Everything that needs DNS. Kept apart because opening a message must never wait on the
     * network: a resolver that is slow or gone would make reading your own post slow or gone.
     *
     * @var list<class-string<Check>>
     */
    public const NETWORK_CHECKS = [
        Checks\Spf::class,
        Checks\DkimSignature::class,
        Checks\DmarcPolicy::class,
        Checks\ReverseDns::class,
        Checks\Blocklisted::class,
    ];

    /** @param list<Finding> $findings */
    public function __construct(public array $findings) {}

    /** @return list<class-string<Check>> */
    public static function withNetwork(): array
    {
        $checks = self::CHECKS;

        // Right behind the header verdict they extend, rather than appended at the end.
        array_splice($checks, 2, 0, self::NETWORK_CHECKS);

        return $checks;
    }

    /** @param list<class-string<Check>>|null $checks */
    public static function build(Context $context, ?array $checks = null): self
    {
        $findings = [];

        foreach ($checks ?? self::CHECKS as $class) {
            $findings[] = (new $class())->run($context);
        }

        return new self($findings);
    }

    public function score(): float
    {
        $penalty = array_sum(array_map(static fn (Finding $f): float => $f->penalty, $this->findings));

        return round(max(0.0, self::MAX_SCORE - $penalty), 1);
    }

    /** @return list<Finding> */
    public function of(Status $status): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->status === $status));
    }

    public function applicable(): int
    {
        return count(array_filter($this->findings, static fn (Finding $f): bool => $f->counts()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'score' => $this->score(),
            'max' => self::MAX_SCORE,
            'applicable' => $this->applicable(),
            'skipped' => count($this->of(Status::Skip)),
            'passed' => count($this->of(Status::Pass)),
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
