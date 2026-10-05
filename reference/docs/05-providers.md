# Providers

Each provider lives under its own route prefix, and the paths after that prefix mirror the real
API exactly. So the only thing your application changes is the host part of the base URL.

Which providers are enabled in your instance, and what each supports, is visible at
`GET /api/providers` and in the sidebar.

## Spryng

We emulate **API v2**. Not v1: it used a different envelope and wanted numbers *without* a leading
plus, so pointing a v1 client at this will not work.

**Base URL**

```
http://msgpit:8080/spryng/v2
```

The official PHP SDK accepts a base URL with a path and does not validate the host or scheme, so
this is a drop-in with no patching.

**Authentication** is `X-Api-Key`, not a bearer token. Some pages of the Spryng documentation
write the header as `Api-Key` without the prefix; msgpit accepts both. Most GET endpoints also
carry an `AccountReference` header, while the send endpoint takes the account in the body as
`accountReference` instead.

**Implemented endpoints**

| Method | Path | Status |
|---|---|---|
| POST | `/v2/messages` | 202 |
| GET | `/v2/balance` | 200 |
| GET | `/v2/webhooks/events` | 200 |
| GET | `/v2/webhooks/subscriptions` | 200 |
| POST | `/v2/webhooks/subscriptions` | 201 |
| PUT | `/v2/webhooks/authentication-methods` | 200 |
| DELETE | `/v2/webhooks/events/{event}` | 204 |

The send endpoint answers 202 rather than 200, because acceptance is not delivery.

The webhook endpoints exist so an application's own settings screen keeps working locally. msgpit
keeps no subscription state: it accepts a registration and answers realistically, but the callback
URL it actually uses is the one in `MSGPIT_SPRYNG_DLR_URL`.

The event names come from the live endpoint, not from Spryng's documentation. The docs list four
names prefixed with `sms-`; the API returns six without that prefix (`message-delivered`,
`message-failed`, `message-received`, `inbound-opted-out`, `message-updated`,
`schedule-updated`).

Balance is the one place where msgpit knowingly contradicts Spryng's documentation. The portal
says 201 with an unwrapped body; both the official SDK and a real consuming application expect 200
with a `data` wrapper, so that is what msgpit returns.

**Recipients** are objects with an `msisdn` in E.164 *with* a plus. One request can carry up to
50,000 of them and returns one `requestId` plus one message id per recipient. msgpit stores one
message per recipient, sharing the request id as `batchId`.

**Variables** in the body text are written between square brackets (`[name]`) and substituted per
recipient. msgpit does the substitution, so the stored body is what that recipient would actually
receive, and the segment count reflects it.

**Errors** use `{"errors":[{"errorCode":..., "errorMessage":...}]}`. There is no 422: validation
failures are 400.

**Delivery reports** have no callback URL in the send request, because Spryng configures webhooks
account-wide. Set `MSGPIT_SPRYNG_DLR_URL` to the endpoint in your application. The payload msgpit
sends is PascalCase (`RequestId`, `Messages[].MessageId`, `MessageParts`, `MessageCoding`), unlike
the rest of the API, and drops the leading plus from the number, because that is what Spryng does.

Two details decide whether your endpoint accepts the report:

- The `metaData` you sent per recipient comes back as `Metadata`, with a lowercase d. Most
  applications use it to find their own record, so without it the report is silently ignored.
- If your endpoint requires a shared secret, set `MSGPIT_SPRYNG_DLR_HEADER` and
  `MSGPIT_SPRYNG_DLR_SECRET`. msgpit then sends that header the way the real Spryng does once you
  register an authentication method. Set both or neither.

Full API notes, including the casing inconsistencies worth knowing about, are in
`src/Provider/Spryng/CLAUDE.md` in the repository.

## Adding one

A provider is a class implementing `Provider`, plus optional capability interfaces for delivery
reports and error scenarios. It parses the request, validates the required fields and the shape of
the authentication, maps to `Message` objects and builds the provider-shaped response. It never
touches storage and never makes an HTTP call.

The core has no knowledge of any concrete provider, so adding one is a new directory and a line in
`providers.php`. The contract test picks it up automatically from its fixtures. Steps are in the
repository README.
