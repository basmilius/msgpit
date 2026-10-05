# HTTP API

The `/api` routes drive the UI, and they are meant just as much for integration tests in the
projects that use msgpit: send a message, assert it arrived and contained the right thing, then
clear.

Everything is JSON. There is no authentication, because msgpit is a local development tool with
no real data in it.

## Messages

### `GET /api/messages`

Newest first. Optional filters, all combinable:

| Parameter | |
|---|---|
| `provider` | Provider id, e.g. `spryng` |
| `channel` | `sms`, `push`, ... |
| `to` | Substring match on the recipient |
| `since` | ISO 8601 timestamp; only messages after it |

```json
{
  "messages": [
    {
      "id": "2138d8c6-f999-41b0-bee4-978336817387",
      "batchId": "80997ed3-aefb-4815-b0cb-37b2e2367845",
      "provider": "spryng",
      "channel": "sms",
      "from": "Acme",
      "to": "+31612345678",
      "body": "Your code is 123456",
      "meta": {"accountReference": "SPNL0000000", "characterSet": "Auto"},
      "providerRef": "6e5676b3-892c-4704-896d-fd3e4f18c095",
      "status": "accepted",
      "encoding": "GSM-7",
      "segments": 1,
      "characters": 19,
      "units": 19,
      "ucs2Offsets": [],
      "createdAt": "2026-09-11T14:27:40Z",
      "readAt": null,
      "read": false
    }
  ],
  "unread": 1
}
```

`providerRef` is the id msgpit handed back to your application, so it is the field to match on if
your code stored one. `batchId` groups the recipients of a single request. `ucs2Offsets` lists the
character positions that forced UCS-2.

A message is unread until someone opens it in the UI, the same way an inbox works. The read state
lives on the server, so it is the same in every tab, and it survives a status change.

### `GET /api/messages/{id}`

The same object plus `rawRequest` (the request as received, rendered as HTTP, secrets masked) and
`deliveryReports`.

### `POST /api/messages/{id}/read`

Marks one message read and answers the remaining `unread` count. The UI calls this when you open a
message; calling it twice is harmless and notifies nothing the second time.

### `POST /api/messages/read`

Marks everything read.

### `DELETE /api/messages`

Clears everything. Answers `204`. Worth calling between test cases.

### `POST /api/messages/{id}/dlr`

Sends a delivery report for one message.

```bash
curl -X POST http://msgpit:8080/api/messages/$ID/dlr \
  -H 'Content-Type: application/json' -d '{"status":"delivered"}'
```

`status` is `delivered` or `failed`. The response says whether a callback was actually sent
(`callbackSent` is false when no `MSGPIT_<PROVIDER>_DLR_URL` is configured) and includes the
delivery reports so far, with what your application answered.

## Scenarios

### `POST /api/scenario`

Arms a one-shot failure for the next provider request. `{"scenario":null}` disarms.

### `GET /api/scenarios`

The catalogue: every scenario, its magic recipient number and a description. Read from the code,
so it cannot drift.

## Providers

### `GET /api/providers`

```json
{"providers": [
  {"id": "spryng", "channels": ["sms"], "deliveryReports": true, "errorScenarios": true}
]}
```

## Live updates

### `GET /api/stream`

Server-Sent Events. Each event is a new message or a status change:

```
id: 12
data: {"type":"message","seq":12,"message":{...}}
```

`type` is `message`, `status`, `read` or `cleared`. Pass `?seq=N` to resume after a known sequence number;
the UI does this on reconnect so nothing is missed.

## Docs

### `GET /api/docs` and `GET /api/docs/{slug}`

The pages you are reading, as Markdown. They live in `docs/` in the repository.

## Health

### `GET /healthz`

`{"status":"ok"}`. Useful as a container healthcheck or to wait on in CI before running tests.

## Using it in a test

```php
// Ask the application to do something that sends an SMS.
$this->post('/password/forgot', ['phone' => '+31612345678']);

$messages = json_decode(file_get_contents('http://msgpit:8080/api/messages?to=%2B31612345678'), true);

self::assertCount(1, $messages['messages']);
self::assertStringContainsString('reset', $messages['messages'][0]['body']);
self::assertSame(1, $messages['messages'][0]['segments']);

// Leave a clean slate for the next test.
file_get_contents('http://msgpit:8080/api/messages', false, stream_context_create([
    'http' => ['method' => 'DELETE'],
]));
```

Asserting on `segments` is worth doing for anything template-driven: it catches the day someone
adds a character that doubles the cost of every message you send.
