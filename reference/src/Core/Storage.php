<?php

declare(strict_types=1);

namespace Msgpit\Core;

use Msgpit\Mime\ParsedMessage;
use PDO;
use PDOStatement;

/**
 * SQLite storage. The schema is created on boot; losing the file is acceptable.
 *
 * @phpstan-type EventRow array{seq: int, type: string, messageId: ?string}
 */
final class Storage
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxMessages = 1000,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // The web server and the SMTP listener are separate processes writing the same file.
        // WAL lets them do that concurrently; the timeout covers the moments they still collide.
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA busy_timeout=5000');

        $this->migrate();
    }

    public static function open(string $path, int $maxMessages = 1000): self
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }

        return new self(new PDO('sqlite:' . $path), $maxMessages);
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messages (
                id TEXT PRIMARY KEY,
                batch_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                channel TEXT NOT NULL,
                sender TEXT,
                recipient TEXT NOT NULL,
                body TEXT NOT NULL,
                meta TEXT NOT NULL DEFAULT '{}',
                provider_ref TEXT,
                status TEXT NOT NULL,
                encoding TEXT,
                segments INTEGER,
                characters INTEGER,
                units INTEGER,
                ucs2_offsets TEXT NOT NULL DEFAULT '[]',
                raw_request TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                read_at TEXT
            );

            CREATE INDEX IF NOT EXISTS messages_created_at ON messages (created_at DESC);

            CREATE TABLE IF NOT EXISTS delivery_reports (
                id TEXT PRIMARY KEY,
                message_id TEXT NOT NULL,
                status TEXT NOT NULL,
                url TEXT NOT NULL,
                request_body TEXT NOT NULL,
                response_status INTEGER,
                response_body TEXT,
                sent_at TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS delivery_reports_message ON delivery_reports (message_id);

            CREATE TABLE IF NOT EXISTS events (
                seq INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                message_id TEXT
            );

            CREATE TABLE IF NOT EXISTS state (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS parts (
                id TEXT PRIMARY KEY,
                message_id TEXT NOT NULL,
                position INTEGER NOT NULL,
                content_type TEXT NOT NULL,
                content_id TEXT,
                filename TEXT,
                disposition TEXT,
                size INTEGER NOT NULL,
                content BLOB NOT NULL
            );

            CREATE INDEX IF NOT EXISTS parts_message ON parts (message_id);
            SQL);

        $this->addColumn('messages', 'read_at', 'TEXT');
    }

    /**
     * A small cache with an expiry, shared by anything that asks the network the same question
     * twice. It outlives the request on purpose: twenty messages from one domain then cost one
     * lookup between them, not twenty.
     */
    public function cached(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM cache WHERE key = :key AND expires_at > :now');
        $statement->execute(['key' => $key, 'now' => time()]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function cache(string $key, string $value, int $ttl): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO cache (key, value, expires_at) VALUES (:key, :value, :expires)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at',
        );
        // No floor on the ttl: a caller that knows an answer is already stale should be able to say
        // so, and an entry written into the past simply never comes back.
        $statement->execute(['key' => $key, 'value' => $value, 'expires' => time() + $ttl]);

        // Cheap enough to do inline, and it keeps a long-lived volume from collecting dead rows.
        $this->pdo->prepare('DELETE FROM cache WHERE expires_at < :now')->execute(['now' => time() - 3600]);
    }

    /** Databases created before a column existed are upgraded in place. */
    private function addColumn(string $table, string $column, string $definition): void
    {
        $statement = $this->pdo->query("PRAGMA table_info({$table})");
        $existing = $statement === false ? [] : array_column(self::rows($statement), 'name');

        if (!in_array($column, $existing, true)) {
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    public function markRead(string $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE messages SET read_at = :read_at WHERE id = :id AND read_at IS NULL',
        );
        $statement->execute(['read_at' => gmdate('Y-m-d\TH:i:s\Z'), 'id' => $id]);

        // Only announce an actual change, so other tabs are not woken for nothing.
        if ($statement->rowCount() > 0) {
            $this->recordEvent('read', $id);
        }
    }

    public function markAllRead(): void
    {
        $statement = $this->pdo->prepare('UPDATE messages SET read_at = :read_at WHERE read_at IS NULL');
        $statement->execute(['read_at' => gmdate('Y-m-d\TH:i:s\Z')]);

        if ($statement->rowCount() > 0) {
            $this->recordEvent('read', null);
        }
    }

    public function unreadCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM messages WHERE read_at IS NULL');

        return $statement === false ? 0 : self::int($statement->fetchColumn());
    }

    /** @param list<Message> $messages */
    public function store(array $messages, RawRequest $rawRequest): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO messages (
                id, batch_id, provider, channel, sender, recipient, body, meta, provider_ref,
                status, encoding, segments, characters, units, ucs2_offsets, raw_request, created_at
            ) VALUES (
                :id, :batch_id, :provider, :channel, :sender, :recipient, :body, :meta, :provider_ref,
                :status, :encoding, :segments, :characters, :units, :ucs2_offsets, :raw_request, :created_at
            )
            SQL);

        foreach ($messages as $message) {
            $statement->execute([
                'id' => $message->id,
                'batch_id' => $message->batchId,
                'provider' => $message->provider,
                'channel' => $message->channel->value,
                'sender' => $message->from,
                'recipient' => $message->to,
                'body' => $message->body,
                'meta' => json_encode($message->meta, JSON_THROW_ON_ERROR),
                'provider_ref' => $message->providerRef,
                'status' => $message->status->value,
                'encoding' => $message->segmentInfo?->encoding->value,
                'segments' => $message->segmentInfo?->segments,
                'characters' => $message->segmentInfo?->characters,
                'units' => $message->segmentInfo?->units,
                'ucs2_offsets' => json_encode($message->segmentInfo->ucs2Offsets ?? [], JSON_THROW_ON_ERROR),
                'raw_request' => $rawRequest->toText(),
                'created_at' => $message->createdAt,
            ]);

            $this->recordEvent('message', $message->id);
        }

        $this->prune();
    }

    /**
     * Mail arrives over SMTP rather than as an HTTP request, so the raw form is the message
     * itself and the MIME parts are stored alongside it.
     *
     * @param list<Message> $messages
     */
    public function storeMail(array $messages, ParsedMessage $parsed): void
    {
        $raw = new RawRequest('SMTP', 'inbound', [], $parsed->raw);

        $this->store($messages, $raw);

        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO parts (id, message_id, position, content_type, content_id, filename, disposition, size, content)
            VALUES (:id, :message_id, :position, :content_type, :content_id, :filename, :disposition, :size, :content)
            SQL);

        foreach ($messages as $message) {
            foreach ($parsed->parts as $position => $part) {
                $statement->bindValue('id', Uuid::v4());
                $statement->bindValue('message_id', $message->id);
                $statement->bindValue('position', $position, PDO::PARAM_INT);
                $statement->bindValue('content_type', $part->contentType);
                $statement->bindValue('content_id', $part->contentId);
                $statement->bindValue('filename', $part->filename);
                $statement->bindValue('disposition', $part->isAttachment() ? 'attachment' : ($part->isInline() ? 'inline' : 'body'));
                $statement->bindValue('size', $part->size(), PDO::PARAM_INT);
                $statement->bindValue('content', $part->content, PDO::PARAM_LOB);
                $statement->execute();
            }
        }
    }

    /**
     * @return list<array{id: string, contentType: string, contentId: ?string, filename: ?string, disposition: string, size: int}>
     */
    public function parts(string $messageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, content_type, content_id, filename, disposition, size
             FROM parts WHERE message_id = :id ORDER BY position ASC',
        );
        $statement->execute(['id' => $messageId]);

        return array_map(static fn (array $row): array => [
            'id' => self::string($row['id']),
            'contentType' => self::string($row['content_type']),
            'contentId' => $row['content_id'] === null ? null : self::string($row['content_id']),
            'filename' => $row['filename'] === null ? null : self::string($row['filename']),
            'disposition' => self::string($row['disposition']),
            'size' => self::int($row['size']),
        ], self::rows($statement));
    }

    /** @return array{contentType: string, filename: ?string, content: string}|null */
    public function part(string $messageId, string $partId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT content_type, filename, content FROM parts WHERE message_id = :message AND id = :id',
        );
        $statement->execute(['message' => $messageId, 'id' => $partId]);
        $rows = self::rows($statement);

        if ($rows === []) {
            return null;
        }

        return [
            'contentType' => self::string($rows[0]['content_type']),
            'filename' => $rows[0]['filename'] === null ? null : self::string($rows[0]['filename']),
            'content' => self::string($rows[0]['content']),
        ];
    }

    /** The part an html body points at with cid:, so the preview can resolve it. */
    public function partByContentId(string $messageId, string $contentId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM parts WHERE message_id = :message AND content_id = :cid',
        );
        $statement->execute(['message' => $messageId, 'cid' => trim($contentId, '<>')]);
        $rows = self::rows($statement);

        return $rows === [] ? null : self::string($rows[0]['id']);
    }

    /**
     * @param array{provider?: string, channel?: string, to?: string, since?: string} $filters
     * @return list<Message>
     */
    public function all(array $filters = []): array
    {
        $where = [];
        $bindings = [];

        foreach (['provider' => 'provider', 'channel' => 'channel'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '') {
                $where[] = "{$column} = :{$filter}";
                $bindings[$filter] = $filters[$filter];
            }
        }

        if (($filters['to'] ?? '') !== '') {
            $where[] = 'recipient LIKE :to';
            $bindings['to'] = '%' . $filters['to'] . '%';
        }

        if (($filters['since'] ?? '') !== '') {
            $where[] = 'created_at > :since';
            $bindings['since'] = $filters['since'];
        }

        $sql = 'SELECT * FROM messages'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY created_at DESC, rowid DESC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return array_map(self::hydrate(...), self::rows($statement));
    }

    public function find(string $id): ?Message
    {
        $statement = $this->pdo->prepare('SELECT * FROM messages WHERE id = :id');
        $statement->execute(['id' => $id]);
        $rows = self::rows($statement);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function rawRequest(string $id): ?string
    {
        $statement = $this->pdo->prepare('SELECT raw_request FROM messages WHERE id = :id');
        $statement->execute(['id' => $id]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function updateStatus(string $id, MessageStatus $status): void
    {
        $statement = $this->pdo->prepare('UPDATE messages SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status->value, 'id' => $id]);

        $this->recordEvent('status', $id);
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM messages');
        $this->pdo->exec('DELETE FROM delivery_reports');
        $this->pdo->exec('DELETE FROM parts');
        $this->recordEvent('cleared', null);
    }

    public function recordDeliveryReport(
        string $messageId,
        DeliveryStatus $status,
        string $url,
        string $requestBody,
        ?int $responseStatus,
        ?string $responseBody,
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO delivery_reports (id, message_id, status, url, request_body, response_status, response_body, sent_at)
            VALUES (:id, :message_id, :status, :url, :request_body, :response_status, :response_body, :sent_at)
            SQL);

        $statement->execute([
            'id' => Uuid::v4(),
            'message_id' => $messageId,
            'status' => $status->value,
            'url' => $url,
            'request_body' => $requestBody,
            'response_status' => $responseStatus,
            'response_body' => $responseBody,
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** @return list<array{status: string, url: string, requestBody: string, responseStatus: ?int, responseBody: ?string, sentAt: string}> */
    public function deliveryReports(string $messageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM delivery_reports WHERE message_id = :id ORDER BY sent_at ASC, rowid ASC',
        );
        $statement->execute(['id' => $messageId]);

        return array_map(static fn (array $row): array => [
            'status' => self::string($row['status']),
            'url' => self::string($row['url']),
            'requestBody' => self::string($row['request_body']),
            'responseStatus' => $row['response_status'] === null ? null : self::int($row['response_status']),
            'responseBody' => $row['response_body'] === null ? null : self::string($row['response_body']),
            'sentAt' => self::string($row['sent_at']),
        ], self::rows($statement));
    }

    public function latestSeq(): int
    {
        $statement = $this->pdo->query('SELECT COALESCE(MAX(seq), 0) FROM events');

        return $statement === false ? 0 : self::int($statement->fetchColumn());
    }

    /** @return list<EventRow> */
    public function eventsSince(int $seq): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM events WHERE seq > :seq ORDER BY seq ASC LIMIT 200');
        $statement->execute(['seq' => $seq]);

        return array_map(static fn (array $row): array => [
            'seq' => self::int($row['seq']),
            'type' => self::string($row['type']),
            'messageId' => $row['message_id'] === null ? null : self::string($row['message_id']),
        ], self::rows($statement));
    }

    /** One-shot: reading the scenario also clears it. */
    public function consumeScenario(): ?Scenario
    {
        $statement = $this->pdo->prepare("SELECT value FROM state WHERE key = 'scenario'");
        $statement->execute();
        $value = $statement->fetchColumn();

        if (!is_string($value)) {
            return null;
        }

        $this->pdo->exec("DELETE FROM state WHERE key = 'scenario'");

        return Scenario::tryFrom($value);
    }

    public function setScenario(?Scenario $scenario): void
    {
        if ($scenario === null) {
            $this->pdo->exec("DELETE FROM state WHERE key = 'scenario'");

            return;
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO state (key, value) VALUES ('scenario', :value)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value",
        );
        $statement->execute(['value' => $scenario->value]);
    }

    private function recordEvent(string $type, ?string $messageId): void
    {
        $statement = $this->pdo->prepare('INSERT INTO events (type, message_id) VALUES (:type, :message_id)');
        $statement->execute(['type' => $type, 'message_id' => $messageId]);
    }

    private function prune(): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM messages WHERE id NOT IN (
                SELECT id FROM messages ORDER BY created_at DESC, rowid DESC LIMIT :limit
            )',
        );
        $statement->bindValue('limit', $this->maxMessages, PDO::PARAM_INT);
        $statement->execute();

        // Attachments are the bulk of the database, so they must not outlive their message.
        $this->pdo->exec('DELETE FROM parts WHERE message_id NOT IN (SELECT id FROM messages)');
        $this->pdo->exec('DELETE FROM delivery_reports WHERE message_id NOT IN (SELECT id FROM messages)');
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Message
    {
        $meta = json_decode(self::string($row['meta']), true);
        $offsets = json_decode(self::string($row['ucs2_offsets']), true);
        $encoding = $row['encoding'] === null ? null : Encoding::from(self::string($row['encoding']));

        return Message::restore(
            id: self::string($row['id']),
            batchId: self::string($row['batch_id']),
            provider: self::string($row['provider']),
            channel: Channel::from(self::string($row['channel'])),
            from: $row['sender'] === null ? null : self::string($row['sender']),
            to: self::string($row['recipient']),
            body: self::string($row['body']),
            meta: self::meta($meta),
            providerRef: $row['provider_ref'] === null ? null : self::string($row['provider_ref']),
            status: MessageStatus::from(self::string($row['status'])),
            segmentInfo: $encoding === null ? null : new SegmentInfo(
                encoding: $encoding,
                segments: self::int($row['segments']),
                characters: self::int($row['characters']),
                units: self::int($row['units']),
                ucs2Offsets: is_array($offsets) ? array_values(array_map(self::int(...), $offsets)) : [],
            ),
            createdAt: self::string($row['created_at']),
            readAt: ($row['read_at'] ?? null) === null ? null : self::string($row['read_at']),
        );
    }

    /**
     * PDO hands back untyped rows; this is the one place that narrows them.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(PDOStatement $statement): array
    {
        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function meta(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
