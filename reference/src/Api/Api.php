<?php

declare(strict_types=1);

namespace Msgpit\Api;

use Msgpit\Core\DeliveryStatus;
use Msgpit\Core\Docs;
use Msgpit\Core\Dns;
use Msgpit\Core\Resolver;
use Msgpit\Core\DlrDispatcher;
use Msgpit\Core\LinkChecker;
use Msgpit\Core\MailCapture;
use Msgpit\Core\Message;
use Msgpit\Core\ProviderRegistry;
use Msgpit\Core\RawRequest;
use Msgpit\Core\SpamAssassin;
use Msgpit\Core\SpamReport;
use Msgpit\Core\Scenario;
use Msgpit\Core\Storage;
use Msgpit\Core\SupportsDeliveryReports;
use Msgpit\Core\SupportsErrorScenarios;
use Msgpit\Http\Request;
use Msgpit\Mail\Report\Context as ReportContext;
use Msgpit\Mail\Report\Report;
use Msgpit\Mime\HtmlCheck;
use Msgpit\Mime\Links;
use Msgpit\Mime\Parser;
use Msgpit\Http\Response;

/** The /api routes: the UI talks to these, and so do integration tests in consuming projects. */
final readonly class Api
{
    /** A mail client can export a message with a video attached; there has to be a ceiling. */
    private const MAX_IMPORT = 30 * 1024 * 1024;

    public function __construct(
        private Storage $storage,
        private ProviderRegistry $registry,
        private DlrDispatcher $dispatcher,
        private Docs $docs,
        private string $version = 'dev',
        private LinkChecker $linkChecker = new LinkChecker(),
        private ?MailCapture $capture = null,
    ) {}

    public function handle(Request $request): ?Response
    {
        $path = rtrim(substr($request->path, strlen('/api')), '/');

        return match (true) {
            $request->method === 'GET' && $path === '/messages' => $this->list($request),
            $request->method === 'DELETE' && $path === '/messages' => $this->clear(),
            $request->method === 'POST' && $path === '/messages/read' => $this->markAllRead(),
            $request->method === 'POST' && $path === '/messages/import' => $this->import($request),
            $request->method === 'GET' && $path === '/providers' => $this->providers(),
            $request->method === 'POST' && $path === '/scenario' => $this->scenario($request),
            $request->method === 'GET' && $path === '/scenarios' => $this->scenarios(),
            $request->method === 'GET' && $path === '/docs' => Response::json(['pages' => $this->docs->index()]),
            default => $this->messageRoutes($request, $path),
        };
    }

    /**
     * Takes in a .eml dropped on the UI. The body is the file as it came off disk, because a
     * mail client exports the message verbatim and re-encoding it would change what we are asked
     * to judge.
     */
    private function import(Request $request): Response
    {
        if (trim($request->body) === '') {
            return Response::json(['error' => 'An empty file is not a message.'], 400);
        }

        if (strlen($request->body) > self::MAX_IMPORT) {
            return Response::json(['error' => 'That file is larger than msgpit accepts.'], 413);
        }

        // Percent encoded by the UI: a header carries latin-1, and mail files are named in Dutch.
        $filename = rawurldecode($request->headers['x-msgpit-filename'] ?? '');
        $capture = $this->capture ?? new MailCapture($this->storage, SpamAssassin::fromEnvironment());

        $stored = $capture->import($request->body, $filename !== '' ? $filename : null);

        return Response::json(['imported' => $stored], 201);
    }

    private function messageRoutes(Request $request, string $path): ?Response
    {
        if (preg_match('#^/docs/([^/]+)$#', $path, $matches) === 1 && $request->method === 'GET') {
            $markdown = $this->docs->page($matches[1]);

            return $markdown === null
                ? Response::json(['error' => 'Page not found.'], 404)
                : Response::json(['slug' => $matches[1], 'markdown' => $markdown]);
        }

        if (preg_match('#^/messages/([^/]+)$#', $path, $matches) === 1 && $request->method === 'GET') {
            return $this->detail($matches[1]);
        }

        if (preg_match('#^/messages/([^/]+)/dlr$#', $path, $matches) === 1 && $request->method === 'POST') {
            return $this->sendDeliveryReport($matches[1], $request);
        }

        if (preg_match('#^/messages/([^/]+)/authentication$#', $path, $matches) === 1 && $request->method === 'POST') {
            return $this->checkAuthentication($matches[1]);
        }

        if (preg_match('#^/messages/([^/]+)/links$#', $path, $matches) === 1 && $request->method === 'POST') {
            return $this->checkLinks($matches[1]);
        }

        if (preg_match('#^/messages/([^/]+)/parts/([^/]+)$#', $path, $matches) === 1 && $request->method === 'GET') {
            return $this->part($matches[1], $matches[2], $request);
        }

        if (preg_match('#^/messages/([^/]+)/read$#', $path, $matches) === 1 && $request->method === 'POST') {
            $this->storage->markRead($matches[1]);

            return Response::json(['unread' => $this->storage->unreadCount()]);
        }

        return null;
    }

    private function list(Request $request): Response
    {
        /** @var array{provider?: string, channel?: string, to?: string, since?: string} $filters */
        $filters = array_intersect_key($request->query, array_flip(['provider', 'channel', 'to', 'since']));

        $messages = array_map(static fn (Message $message): array => $message->toArray(), $this->storage->all($filters));

        return Response::json(['messages' => $messages, 'unread' => $this->storage->unreadCount()]);
    }

    private function detail(string $id): Response
    {
        $message = $this->storage->find($id);

        if ($message === null) {
            return Response::json(['error' => 'Message not found.'], 404);
        }

        $detail = $message->toArray() + [
            'rawRequest' => $this->storage->rawRequest($id),
            'deliveryReports' => $this->storage->deliveryReports($id),
        ];

        // Mail carries its MIME parts along: the bodies to render, the images the html points at,
        // and the attachments to offer. Everything else has none.
        $parts = $this->storage->parts($id);

        if ($parts !== []) {
            $detail['parts'] = $parts;

            // Every header the sender wrote, not the handful we keep in metadata. Read back from
            // the stored message so nothing has to be duplicated at capture time, and so a
            // message stored before we cared about a header still shows it.
            $raw = $this->storage->rawRequest($id) ?? '';
            $detail['headers'] = Parser::headers(
                explode("\n\n", RawRequest::messageFrom(str_replace("\r\n", "\n", $raw)), 2)[0],
            );
            $detail['html'] = $this->body($id, $parts, 'text/html');
            $detail['text'] = $this->body($id, $parts, 'text/plain');

            // Worked out per request rather than stored: the compatibility data is updated now and
            // then, and a score from six months ago would be quietly wrong.
            if ($detail['html'] !== null) {
                $detail['htmlCheck'] = HtmlCheck::analyse($detail['html'])?->toArray();
            }

            // Listed here, but never fetched: see the link check endpoint.
            $detail['links'] = Links::find($detail['html'], $detail['text']);

            // Worked out per request as well, and for the same reason: it leans on the spam score
            // and the compatibility data, and both move underneath it.
            $detail['report'] = $this->report($message, $detail['html'], $detail['text'])->toArray();
        }

        return Response::json($detail);
    }

    private function report(Message $message, ?string $html, ?string $text, ?Resolver $dns = null): Report
    {
        /** @var array<string, mixed> $spamMeta */
        $spamMeta = is_array($message->meta['spam'] ?? null) ? $message->meta['spam'] : [];
        $raw = $this->storage->rawRequest($message->id) ?? '';

        $context = new ReportContext(
            mail: Parser::parse(RawRequest::messageFrom(str_replace("\r\n", "\n", $raw))),
            html: $html,
            text: $text,
            spam: $spamMeta === [] ? null : SpamReport::fromArray($spamMeta),
            imported: ($message->meta['imported'] ?? false) === true,
            dns: $dns,
        );

        return Report::build($context, $dns === null ? null : Report::withNetwork());
    }

    /**
     * The checks that ask DNS, on request only. Opening a message must not wait on a resolver, and
     * this is a second way out of the development network next to callbacks and the link check.
     */
    private function checkAuthentication(string $id): Response
    {
        $message = $this->storage->find($id);
        $parts = $message === null ? [] : $this->storage->parts($id);

        if ($message === null || $parts === []) {
            return Response::json(['error' => 'Message not found.'], 404);
        }

        $dns = Dns::fromEnvironment($this->storage);

        if (!$dns->enabled()) {
            return Response::json(['error' => 'DNS lookups are switched off with MSGPIT_DNS.'], 409);
        }

        $report = $this->report($message, $this->body($id, $parts, 'text/html'), $this->body($id, $parts, 'text/plain'), $dns);

        return Response::json(['report' => $report->toArray(), 'lookups' => $dns->lookups()]);
    }

    /**
     * Reaches outside the development network, so it happens only when asked. Links in mail carry
     * one-shot tokens: fetching a reset or unsubscribe link can spend it.
     */
    private function checkLinks(string $id): Response
    {
        $message = $this->storage->find($id);

        if ($message === null) {
            return Response::json(['error' => 'Message not found.'], 404);
        }

        $parts = $this->storage->parts($id);
        $links = Links::find($this->body($id, $parts, 'text/html'), $this->body($id, $parts, 'text/plain'));

        return Response::json(['links' => $this->linkChecker->check($links)]);
    }

    /**
     * @param list<array{id: string, contentType: string, contentId: ?string, filename: ?string, disposition: string, size: int}> $parts
     */
    private function body(string $messageId, array $parts, string $contentType): ?string
    {
        foreach ($parts as $part) {
            if ($part['contentType'] === $contentType && $part['disposition'] === 'body') {
                $content = $this->storage->part($messageId, $part['id']);

                return $content === null ? null : $content['content'];
            }
        }

        return null;
    }

    /**
     * Serves one MIME part: an image the html refers to, or an attachment to save. The content
     * type comes from the message, so it is never trusted blindly for rendering; the UI shows
     * images and offers everything else as a download.
     */
    private function part(string $messageId, string $partId, Request $request): Response
    {
        $part = $this->storage->part($messageId, $partId);

        if ($part === null) {
            return Response::json(['error' => 'Part not found.'], 404);
        }

        $headers = [
            'Content-Type' => self::safeContentType($part['contentType']),
            'Content-Length' => (string) strlen($part['content']),
            // Captured mail is throwaway, and a stale image after a re-send is confusing.
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (($request->query['download'] ?? '') !== '' || $part['filename'] !== null) {
            $disposition = ($request->query['download'] ?? '') !== '' ? 'attachment' : 'inline';
            $filename = $part['filename'] ?? 'part';
            $headers['Content-Disposition'] = $disposition . '; filename="' . self::quoteFilename($filename) . '"'
                . "; filename*=UTF-8''" . rawurlencode($filename);
        }

        return new Response(200, $part['content'], $headers);
    }

    /**
     * A part claims its own content type, and the sender chose it. Anything we would rather the
     * browser did not execute in our own origin is served as a download instead.
     */
    private static function safeContentType(string $contentType): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        $safe = str_starts_with($type, 'image/')
            || str_starts_with($type, 'audio/')
            || str_starts_with($type, 'video/')
            || in_array($type, ['application/pdf', 'text/plain'], true);

        return $safe ? $type : 'application/octet-stream';
    }

    private static function quoteFilename(string $filename): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $filename);

        return str_replace('"', '', $ascii === false ? 'part' : $ascii);
    }

    private function markAllRead(): Response
    {
        $this->storage->markAllRead();

        return Response::json(['unread' => 0]);
    }

    private function clear(): Response
    {
        $this->storage->clear();

        return Response::noContent();
    }

    private function providers(): Response
    {
        $host = gethostname() ?: 'msgpit';
        $providers = array_map(static fn ($provider): array => [
            'id' => $provider->id(),
            'channels' => array_map(static fn ($channel): string => $channel->value, $provider->channels()),
            'deliveryReports' => $provider instanceof SupportsDeliveryReports,
            'errorScenarios' => $provider instanceof SupportsErrorScenarios,
            // What an application should use as its base url, so the UI never has to hardcode it.
            'baseUrl' => "http://{$host}:8080" . ProviderRegistry::baseUrl($provider),
        ], $this->registry->all());

        $smtp = getenv('MSGPIT_SMTP') === '0'
            ? null
            : ['host' => $host, 'port' => (int) (getenv('MSGPIT_SMTP_PORT') ?: 1025)];

        return Response::json([
            'providers' => $providers,
            'smtp' => $smtp,
            'version' => $this->version,
        ]);
    }

    /** The catalogue the reference docs render, so the magic numbers are never copied by hand. */
    private function scenarios(): Response
    {
        $scenarios = array_map(static fn (Scenario $scenario): array => [
            'scenario' => $scenario->value,
            'recipient' => $scenario->recipient(),
            'description' => $scenario->description(),
        ], Scenario::cases());

        return Response::json(['scenarios' => $scenarios]);
    }

    private function scenario(Request $request): Response
    {
        $value = $request->json()['scenario'] ?? null;
        $scenario = is_string($value) ? Scenario::tryFrom($value) : null;

        if (is_string($value) && $value !== '' && $scenario === null) {
            return Response::json(['error' => 'Unknown scenario.'], 400);
        }

        $this->storage->setScenario($scenario);

        return Response::json(['scenario' => $scenario?->value]);
    }

    private function sendDeliveryReport(string $id, Request $request): Response
    {
        $message = $this->storage->find($id);

        if ($message === null) {
            return Response::json(['error' => 'Message not found.'], 404);
        }

        $value = $request->json()['status'] ?? null;
        $status = is_string($value) ? DeliveryStatus::tryFrom($value) : null;

        if ($status === null) {
            return Response::json(['error' => "Status must be 'delivered' or 'failed'."], 400);
        }

        $provider = $this->registry->get($message->provider);
        $callback = $provider instanceof SupportsDeliveryReports
            ? $provider->deliveryReport($message, $status)
            : null;

        $this->dispatcher->dispatch($id, $status, $callback);

        return Response::json([
            'status' => $status->value,
            'callbackSent' => $callback !== null,
            'deliveryReports' => $this->storage->deliveryReports($id),
        ]);
    }
}
