<?php

declare(strict_types=1);

namespace Msgpit\Core;

/** One stored message per recipient; a multi-recipient request shares one batchId. */
final readonly class Message
{
    /** @param array<string, mixed> $meta Push title/data, template info, provider extras. */
    private function __construct(
        public string $id,
        public string $batchId,
        public string $provider,
        public Channel $channel,
        public ?string $from,
        public string $to,
        public string $body,
        public array $meta,
        public ?string $providerRef,
        public MessageStatus $status,
        public ?SegmentInfo $segmentInfo,
        public string $createdAt,
        public ?string $readAt = null,
    ) {}

    /** @param array<string, mixed> $meta */
    public static function create(
        string $batchId,
        string $provider,
        Channel $channel,
        string $to,
        string $body,
        ?string $from = null,
        ?string $providerRef = null,
        array $meta = [],
    ): self {
        return new self(
            id: Uuid::v4(),
            batchId: $batchId,
            provider: $provider,
            channel: $channel,
            from: $from,
            to: $to,
            body: $body,
            meta: $meta,
            providerRef: $providerRef,
            status: MessageStatus::Accepted,
            // Only SMS is billed in segments, so only SMS gets the analysis.
            segmentInfo: $channel === Channel::Sms ? Segments::analyze($body) : null,
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * Rebuilds a stored message. Storage is the only caller: it casts the database columns and
     * passes them in typed, so nothing mixed reaches the constructor.
     *
     * @param array<string, mixed> $meta
     */
    public static function restore(
        string $id,
        string $batchId,
        string $provider,
        Channel $channel,
        ?string $from,
        string $to,
        string $body,
        array $meta,
        ?string $providerRef,
        MessageStatus $status,
        ?SegmentInfo $segmentInfo,
        string $createdAt,
        ?string $readAt = null,
    ): self {
        return new self(
            id: $id,
            batchId: $batchId,
            provider: $provider,
            channel: $channel,
            from: $from,
            to: $to,
            body: $body,
            meta: $meta,
            providerRef: $providerRef,
            status: $status,
            segmentInfo: $segmentInfo,
            createdAt: $createdAt,
            readAt: $readAt,
        );
    }

    /** @return array<string, mixed> The shape the /api routes and the UI use. */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'batchId' => $this->batchId,
            'provider' => $this->provider,
            'channel' => $this->channel->value,
            'from' => $this->from,
            'to' => $this->to,
            'body' => $this->body,
            'meta' => $this->meta,
            'providerRef' => $this->providerRef,
            'status' => $this->status->value,
            'encoding' => $this->segmentInfo?->encoding->value,
            'segments' => $this->segmentInfo?->segments,
            'characters' => $this->segmentInfo?->characters,
            'units' => $this->segmentInfo?->units,
            'ucs2Offsets' => $this->segmentInfo->ucs2Offsets ?? [],
            'createdAt' => $this->createdAt,
            'readAt' => $this->readAt,
            'read' => $this->readAt !== null,
        ];
    }

    public function withStatus(MessageStatus $status): self
    {
        return self::restore(
            $this->id, $this->batchId, $this->provider, $this->channel, $this->from, $this->to,
            $this->body, $this->meta, $this->providerRef, $status, $this->segmentInfo,
            $this->createdAt, $this->readAt,
        );
    }
}
