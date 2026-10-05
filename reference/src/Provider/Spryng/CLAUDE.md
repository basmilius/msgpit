# Spryng provider

Emulates the **Spryng API v2**. Not v1: v1 wanted numbers without a leading plus and used a
different envelope entirely. Reference material (SDK, scraped portal docs, OpenAPI) lives outside
this repo at `~/Projects/spryng-v2-api`; the v2 API is poorly documented online.

- Route prefix: `spryng`, so our base URL is `http://msgpit:8080/spryng/v2`.
- Channel: `sms` only.
- Capabilities: `SupportsDeliveryReports`, `SupportsErrorScenarios`.

## Base URL

The real base is `https://api.spryng.nl/v2`; the version sits in the path. The official PHP SDK
takes an optional base URL and only does `rtrim($base, '/') . '/' . ltrim($path, '/')`, so a base
URL **with a path is fine** and plain http works. Consuming projects need no patched SDK: point
the base URL at `http://msgpit:8080/spryng/v2` and everything else stays identical.

## Authentication

`X-Api-Key: <key>`. There is no `Authorization` header and no OAuth. Some doc pages write
`Api-Key` without the prefix, so we accept both. We check the header is present and non-empty and
never look at the value.

Most GET endpoints also carry an `AccountReference` header (format `SPNL` + 7 digits). On
`POST /v2/messages` the account sits in the body as `accountReference` instead. Two doc pages
misspell the header as `AcccountReference`; that is a typo in the docs, not a second header.

## Implemented endpoints

| Method | Path | Status | Notes |
|---|---|---|---|
| POST | `/v2/messages` | **202** | Send. Not 200. |
| GET | `/v2/balance` | 200 | Wrapped in `data`. See the note below. |
| GET | `/v2/webhooks/events` | 200 | `data` is a list of `{id, name, description}`. |
| GET | `/v2/webhooks/subscriptions` | 200 | `data.events`, derived from `MSGPIT_SPRYNG_DLR_URL`. |
| POST | `/v2/webhooks/subscriptions` | **201** | Accepted and forgotten. |
| PUT | `/v2/webhooks/authentication-methods` | 200 | Accepted and forgotten. |
| DELETE | `/v2/webhooks/events/{event}` | 204 | |

The real API has 42 paths (contacts, groups, templates, schedules, throttling, webhooks, inbox,
url-shortener). We only add what our apps actually call.

### The balance response contradicts itself

The portal page and the generated OpenAPI both say **201 Created** with a bare
`{"available", "reserved"}` body. Two independent clients disagree: the official SDK reads it
through its `data()` helper, and a real consuming application expects `200` with `data.available`.
The OpenAPI is generated from the same portal page, so it is not a second source.

We follow the clients: **200, wrapped**. This is the one place where we knowingly contradict the
documentation, and it is worth re-checking against the live API when someone has a key, because
getting it wrong here means the emulation is friendlier than production.

## Send request

```json
{
    "accountReference": "SPNL0000000",
    "channel": "SMS",
    "from": "Acme",
    "body": {"text": "Your code is 123456"},
    "recipients": [{"msisdn": "+31612345678", "variables": {"name": "Ada"}}]
}
```

- `accountReference`, `channel` (only `SMS`), and `body` are required. `body` needs either `text`
  or a `templateId`.
- `recipients` holds 1 to 50,000 objects; `msisdn` must be E.164 **with** a plus
  (`/^\+[1-9]\d{6,14}$/`).
- `recipients` and `addressBook` are mutually exclusive.
- Optional: `name`, `characterSet` (`Auto`|`GSM`|`Unicode`), `validity`, `messageType`, `metaData`.
- Variables in the text are written between square brackets (`[name]`) and are substituted per
  recipient. We do this substitution so the stored body is what the recipient would actually get.
- The SDK omits empty `variables`/`metaData`, but the live API often receives them as `{}`. Accept
  both.

## Send response

```json
{"data": {"requestId": "8dcad127-bb9c-48fe-9827-db2fe57d53fb",
          "messageIds": ["0929df71-d1ea-4b02-87b2-9789b332a92e"]}}
```

Lowercase UUID v4 with dashes, one messageId per recipient in send order. The `requestId` is our
`batchId`, each messageId the message's `providerRef`. Every response is wrapped in `data`,
**except** `/v2/balance`.

## Errors

```json
{"errors": [{"errorCode": "msisdn_invalid", "errorMessage": "..."}]}
```

- **There is no 422.** Validation failures are **400**. Error codes are snake_case.
- 401 is the only shape verified against the live API:
  `{"errors":[{"errorCode":"UnauthenticatedError","errorMessage":"Request for authenticated route '<path>' was unauthenticated"}]}`.
  The path in the message is dynamic. Doc pages disagree on the casing of the code
  (`unauthenticatedError` 39 times, `UnauthenticatedError` 4 times); the live capture gave the
  capitalised form, so that is what we return.
- 429 is documented only generically: one undisclosed limit across all endpoints, and wait 5
  seconds afterwards. No `X-RateLimit-*` headers exist, but the SDK reads `Retry-After`, so we
  send it.
- 500 is undocumented; we keep the same envelope.

## Delivery reports

Spryng has **no callback URL in the send request**. Webhooks are configured account-wide through
`POST /v2/webhooks/subscriptions`, per event. So our callback URL comes from
`MSGPIT_SPRYNG_DLR_URL`; without it the UI still flips the status but sends nothing.

We keep no subscription state: the subscribe and authentication-method endpoints accept the call
and answer realistically, but change nothing. `GET /v2/webhooks/subscriptions` reports the URL
from the environment, which is more honest than an empty list because it is what msgpit will
actually call.

### The event names in the documentation are wrong

The docs name four events with an `sms-` prefix: `sms-message-delivered` and friends. The live
`GET /v2/webhooks/events` returns **six, without the prefix**:

```
message-delivered   message-failed     message-received
inbound-opted-out   message-updated    schedule-updated
```

Go by the endpoint. A client lists its own subscriptions and matches them against this catalogue
to show which events it listens to; with the documented names nothing matches and every event
looks unsubscribed, with no error to explain it. `tests/Unit/SpryngWebhookTest.php` pins both the
names and the rule that a subscription may only name an event this catalogue lists.

The callback carries two things that are easy to miss and break the receiving end silently:

- **`Metadata`**, spelled with a lowercase d even though it goes in as `metaData`. It is the
  recipient's `metaData` from the send request, echoed back. Applications key their own records
  off it, so a report without it is dropped on the floor with no error anywhere. It is an empty
  object rather than an empty array when there was none, because a client expecting an object
  chokes on `[]`.
- **An authentication header.** The real Spryng authenticates itself to your endpoint with a
  header you register through `PUT /v2/webhooks/authentication-methods`. We take it from
  `MSGPIT_SPRYNG_DLR_HEADER` and `MSGPIT_SPRYNG_DLR_SECRET` instead, so it works whether or not
  the app ever made that call. Both must be set, or neither is sent.

The payload is **PascalCase**, unlike the rest of the API, and drops the leading plus from the
number:

```json
{
  "RequestId": "afe6beb6-...", "AccountReference": "SPNL0000000", "Channel": "SMS",
  "Status": "Delivered",
  "Messages": [{"MessageId": "31b2dbc7-...", "Status": "Delivered", "Reason": "Delivered",
                "Body": "...", "MessageParts": 2, "MessageCoding": "GSM",
                "Msisdn": "447897890440", "Originator": "Acme"}]
}
```

Status values the API uses: `Delivered | Received | Failed | In Progress | Sent`. The `reason`
enum includes `Delivered`, `MsisdnInvalid`, `NetworkFailed`, `OptedOut`, `SendingLimitExceeded`
and more.

## Encoding and segments

Three names for the same thing, one per endpoint. Mirror this, do not normalise it:

| Where | Field | Values |
|---|---|---|
| Send request | `characterSet` | `Auto`, `GSM`, `Unicode` |
| Message response | `characterSet` | `Gsm`, `Unicode` |
| Webhook | `MessageCoding` | `GSM`, `Unicode` |
| Webhook / list | `MessageParts` / `messagePartCount` | integer |

Segment counting itself is core's job (`Core\Segments`), not the provider's.

## Quirks worth remembering

1. Send answers 202, balance answers 201.
2. `data` wrapper everywhere except balance.
3. No `Authorization` header; `X-Api-Key`, tolerant of `Api-Key`.
4. No 422; validation is 400.
5. `GET /v2/requests` uses PascalCase query filters while `GET /v2/messages` uses lowercase.
6. Query arrays repeat the key (`?contactId=a&contactId=b`), never `contactId[]`.
7. Timestamps the SDK sends are `Y-m-d\TH:i:s.v\Z`; the ones the API returns have 7 decimals.
