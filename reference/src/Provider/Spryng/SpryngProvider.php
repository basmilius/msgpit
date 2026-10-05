<?php

declare(strict_types=1);

namespace Msgpit\Provider\Spryng;

use Msgpit\Core\Capture;
use Msgpit\Core\Channel;
use Msgpit\Core\DeliveryStatus;
use Msgpit\Core\Message;
use Msgpit\Core\Provider;
use Msgpit\Core\Route;
use Msgpit\Core\Scenario;
use Msgpit\Core\SupportsDeliveryReports;
use Msgpit\Core\SupportsErrorScenarios;
use Msgpit\Core\Uuid;
use Msgpit\Http\OutgoingRequest;
use Msgpit\Http\Request;
use Msgpit\Http\Response;

/**
 * Spryng API v2. Quirks worth knowing, all mirrored here:
 * auth is X-Api-Key (not Bearer), send answers 202, every body is wrapped in "data" except
 * balance, validation errors are 400 (there is no 422), and the character set is spelled
 * differently per endpoint.
 */
final class SpryngProvider implements Provider, SupportsDeliveryReports, SupportsErrorScenarios
{
    /** The two events a delivery report can belong to, named as the events endpoint names them. */
    private const DELIVERY_EVENTS = ['message-delivered', 'message-failed'];

    private const ACCOUNT_REFERENCE = '/^SPNL\d{7}$/';
    private const MSISDN = '/^\+[1-9]\d{6,14}$/';

    public function id(): string
    {
        return 'spryng';
    }

    public function channels(): array
    {
        return [Channel::Sms];
    }

    public function routes(): array
    {
        return [
            new Route('POST', '/v2/messages', $this->send(...)),
            new Route('GET', '/v2/balance', $this->balance(...)),
            new Route('GET', '/v2/webhooks/events', $this->events(...)),
            new Route('GET', '/v2/webhooks/subscriptions', $this->subscriptions(...)),
            new Route('POST', '/v2/webhooks/subscriptions', $this->subscribe(...)),
            new Route('PUT', '/v2/webhooks/authentication-methods', $this->authenticationMethod(...)),
            new Route('DELETE', '/v2/webhooks/events/{event}', $this->unsubscribe(...)),
        ];
    }

    /** @param array<string, string> $params */
    private function send(Request $request, array $params): Capture
    {
        if (!$this->isAuthenticated($request)) {
            return new Capture([], self::unauthenticated($request->path));
        }

        $payload = $request->json();
        $error = $this->validate($payload);

        if ($error !== null) {
            return new Capture([], $error);
        }

        /** @var array{text?: string, templateId?: string} $body */
        $body = is_array($payload['body'] ?? null) ? $payload['body'] : [];
        /** @var list<array<string, mixed>> $recipients */
        $recipients = array_values(array_filter(
            is_array($payload['recipients'] ?? null) ? $payload['recipients'] : [],
            'is_array',
        ));

        $requestId = Uuid::v4();
        $messages = [];
        $messageIds = [];

        foreach ($recipients as $recipient) {
            $msisdn = is_string($recipient['msisdn'] ?? null) ? $recipient['msisdn'] : '';
            $providerRef = Uuid::v4();
            $messageIds[] = $providerRef;

            $messages[] = Message::create(
                batchId: $requestId,
                provider: $this->id(),
                channel: Channel::Sms,
                to: $msisdn,
                body: $this->renderBody($body, $recipient),
                from: is_string($payload['from'] ?? null) ? $payload['from'] : null,
                providerRef: $providerRef,
                meta: $this->meta($payload, $recipient),
            );
        }

        return new Capture(
            $messages,
            Response::json(['data' => ['requestId' => $requestId, 'messageIds' => $messageIds]], 202),
        );
    }

    /** @param array<string, string> $params */
    private function balance(Request $request, array $params): Capture
    {
        if (!$this->isAuthenticated($request)) {
            return new Capture([], self::unauthenticated($request->path));
        }

        // The docs claim 201 and no data wrapper here. Real clients disagree, and they carry
        // details the docs never mention (the "Wallet management" 400 for invoiced accounts), so
        // we follow the clients: 200, wrapped, amounts as strings.
        return new Capture([], Response::json([
            'data' => ['available' => '2951.73346', 'reserved' => '0'],
        ]));
    }

    /**
     * The events the live API returns from this endpoint. The documentation calls them
     * "sms-message-delivered" and so on; the endpoint itself has no "sms-" prefix and lists six
     * rather than four. The endpoint wins: clients match their own subscriptions against this
     * list, and a prefix that only exists in the docs leaves every event looking unsubscribed.
     *
     * @param array<string, string> $params
     */
    private function events(Request $request, array $params): Capture
    {
        if (!$this->isAuthenticated($request)) {
            return new Capture([], self::unauthenticated($request->path));
        }

        $events = array_map(static fn (array $event): array => [
            'id' => $event[0],
            'name' => $event[1],
            'description' => $event[2],
        ], [
            ['message-delivered', 'Message delivered', 'An outbound message reached the handset.'],
            ['message-failed', 'Message failed', 'An outbound message could not be delivered.'],
            ['message-received', 'Message received', 'An inbound message arrived on a VMN or shortcode.'],
            ['inbound-opted-out', 'Opted out', 'A recipient opted out of further messages.'],
            ['message-updated', 'Message updated', 'A message changed after it was submitted.'],
            ['schedule-updated', 'Schedule updated', 'A scheduled send changed.'],
        ]);

        return new Capture([], Response::json(['data' => $events]));
    }

    /**
     * We keep no subscription state: the callback URL comes from MSGPIT_SPRYNG_DLR_URL. Reporting
     * that URL back is more honest than an empty list, because it is what msgpit will actually
     * call.
     *
     * @param array<string, string> $params
     */
    private function subscriptions(Request $request, array $params): Capture
    {
        if (!$this->isAuthenticated($request)) {
            return new Capture([], self::unauthenticated($request->path));
        }

        $url = self::callbackUrl();
        $events = $url === null ? [] : array_map(static fn (string $event): array => [
            'eventType' => $event,
            'callbacks' => [['url' => $url]],
            'requiresAuthentication' => self::authenticationHeader() !== null,
        ], self::DELIVERY_EVENTS);

        return new Capture([], Response::json(['data' => ['events' => $events]]));
    }

    /** @param array<string, string> $params */
    private function subscribe(Request $request, array $params): Capture
    {
        if (!$this->isAuthenticated($request)) {
            return new Capture([], self::unauthenticated($request->path));
        }

        // Accepted and forgotten: msgpit calls back to MSGPIT_SPRYNG_DLR_URL regardless.
        return new Capture([], Response::json(['data' => ['created' => true]], 201));
    }

    /** @param array<string, string> $params */
    private function authenticationMethod(Request $request, array $params): Capture
    {
        if (!$this->isAuthenticated($request)) {
            return new Capture([], self::unauthenticated($request->path));
        }

        // The header msgpit sends comes from the environment, not from this call, so that a
        // delivery report works even if the app never registers anything.
        return new Capture([], Response::json(['data' => ['updated' => true]]));
    }

    /** @param array<string, string> $params */
    private function unsubscribe(Request $request, array $params): Capture
    {
        if (!$this->isAuthenticated($request)) {
            return new Capture([], self::unauthenticated($request->path));
        }

        return new Capture([], new Response(204));
    }

    /**
     * Shape check only: we never validate the key itself. Some Spryng doc pages spell the
     * header without the X- prefix, so both are accepted.
     */
    private function isAuthenticated(Request $request): bool
    {
        return ($request->header('X-Api-Key') ?? $request->header('Api-Key') ?? '') !== '';
    }

    /** @param array<string, mixed> $payload */
    private function validate(array $payload): ?Response
    {
        $account = $payload['accountReference'] ?? null;

        if (!is_string($account) || preg_match(self::ACCOUNT_REFERENCE, $account) !== 1) {
            return self::error('accountReference_invalid', 'A valid accountReference must be provided.');
        }

        if (($payload['channel'] ?? null) !== 'SMS') {
            return self::error('channel_invalid', "Channel must be 'SMS'.");
        }

        $body = $payload['body'] ?? null;

        if (!is_array($body) || (!is_string($body['text'] ?? null) && !is_string($body['templateId'] ?? null))) {
            return self::error('body_invalid', 'Either body.text or body.templateId must be provided.');
        }

        $recipients = $payload['recipients'] ?? null;

        if (isset($payload['addressBook']) && is_array($recipients)) {
            return self::error('recipients_invalid', 'Provide either recipients or addressBook, not both.');
        }

        if (!is_array($recipients) || $recipients === []) {
            return self::error('recipients_empty', 'At least one recipient must be provided.');
        }

        foreach ($recipients as $recipient) {
            $msisdn = is_array($recipient) ? ($recipient['msisdn'] ?? null) : null;

            if (!is_string($msisdn) || preg_match(self::MSISDN, $msisdn) !== 1) {
                return self::error('msisdn_invalid', 'Recipient msisdn must be in E.164 format.');
            }
        }

        return null;
    }

    /**
     * @param array{text?: string, templateId?: string} $body
     * @param array<string, mixed> $recipient
     */
    private function renderBody(array $body, array $recipient): string
    {
        $text = $body['text'] ?? '';
        $variables = is_array($recipient['variables'] ?? null) ? $recipient['variables'] : [];

        foreach ($variables as $name => $value) {
            if (is_scalar($value)) {
                $text = str_replace('[' . (string) $name . ']', (string) $value, $text);
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $recipient
     * @return array<string, mixed>
     */
    private function meta(array $payload, array $recipient): array
    {
        $meta = [
            'accountReference' => $payload['accountReference'] ?? null,
            'characterSet' => $payload['characterSet'] ?? 'Auto',
            'messageType' => $payload['messageType'] ?? null,
            'requestName' => $payload['name'] ?? null,
            'validity' => $payload['validity'] ?? null,
            'templateId' => is_array($payload['body'] ?? null) ? ($payload['body']['templateId'] ?? null) : null,
            'variables' => $recipient['variables'] ?? null,
            'recipientMetaData' => $recipient['metaData'] ?? null,
            'requestMetaData' => $payload['metaData'] ?? null,
        ];

        return array_filter($meta, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function deliveryReport(Message $message, DeliveryStatus $status): ?OutgoingRequest
    {
        $url = self::callbackUrl();

        if ($url === null) {
            return null;
        }

        $segmentInfo = $message->segmentInfo;

        // The webhook is PascalCase and drops the leading plus, unlike the rest of the API.
        $payload = [
            'RequestId' => $message->batchId,
            'AccountReference' => $message->meta['accountReference'] ?? 'SPNL0000000',
            'Channel' => 'SMS',
            'Status' => $status === DeliveryStatus::Delivered ? 'Delivered' : 'Failed',
            'Messages' => [[
                'MessageId' => $message->providerRef,
                'MostRecentOutboundMessageId' => null,
                'MostRecentOutboundRequestId' => null,
                'OccurredAtTime' => gmdate('Y-m-d\TH:i:s.u\Z'),
                'Status' => $status === DeliveryStatus::Delivered ? 'Delivered' : 'Failed',
                'Reason' => $status === DeliveryStatus::Delivered ? 'Delivered' : 'NetworkFailed',
                'DestinationCode' => '',
                'Body' => $message->body,
                'MessageParts' => $segmentInfo->segments ?? 1,
                'MessageCoding' => $segmentInfo?->encoding->value === 'UCS-2' ? 'Unicode' : 'GSM',
                'ContactId' => null,
                'Originator' => $message->from,
                'Msisdn' => ltrim($message->to, '+'),
                'SenderType' => 'AlphanumericSenderId',
                // Spelled with a lowercase d, unlike the metaData that went in. Apps key their
                // own records off this, so a report without it is silently discarded.
                'Metadata' => $message->meta['recipientMetaData'] ?? new \stdClass(),
            ]],
        ];

        $headers = ['Content-Type' => 'application/json'];
        $authentication = self::authenticationHeader();

        if ($authentication !== null) {
            [$name, $value] = $authentication;
            $headers[$name] = $value;
        }

        return new OutgoingRequest(
            method: 'POST',
            url: $url,
            headers: $headers,
            body: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /** Spryng has no callback URL in the send request: webhooks are configured account-wide. */
    private static function callbackUrl(): ?string
    {
        $url = getenv('MSGPIT_SPRYNG_DLR_URL');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The real Spryng authenticates itself to your webhook with a header you register through
     * PUT /v2/webhooks/authentication-methods. We take it from the environment instead, so a
     * delivery report works whether or not the app ever made that call.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function authenticationHeader(): ?array
    {
        $name = getenv('MSGPIT_SPRYNG_DLR_HEADER');
        $value = getenv('MSGPIT_SPRYNG_DLR_SECRET');

        if (!is_string($name) || $name === '' || !is_string($value) || $value === '') {
            return null;
        }

        return [$name, $value];
    }

    public function errorResponse(Scenario $scenario): Response
    {
        return match ($scenario) {
            Scenario::InvalidNumber => self::error('msisdn_invalid', 'Recipient msisdn must be in E.164 format.'),
            Scenario::Unauthorized => self::unauthenticated('/v2/messages'),
            Scenario::RateLimited => new Response(
                429,
                self::errorBody('rate_limit_exceeded', 'Too many requests. Retry after 5 seconds.'),
                ['Content-Type' => 'application/json', 'Retry-After' => '5'],
            ),
            Scenario::ServerError => self::error('internal_error', 'An unexpected error occurred.', 500),
        };
    }

    private static function unauthenticated(string $path): Response
    {
        return self::error(
            'UnauthenticatedError',
            "Request for authenticated route '{$path}' was unauthenticated",
            401,
        );
    }

    /** Validation failures are 400: Spryng has no 422. */
    private static function error(string $code, string $message, int $status = 400): Response
    {
        return new Response($status, self::errorBody($code, $message), ['Content-Type' => 'application/json']);
    }

    private static function errorBody(string $code, string $message): string
    {
        return json_encode(
            ['errors' => [['errorCode' => $code, 'errorMessage' => $message]]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
