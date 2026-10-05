# Spryng fixtures

Cases are taken from the official API documentation and the SDK's own tests, mirrored at
`~/Projects/spryng-v2-api`:

- `docs/api/messaging/v2-messages-create.md` - send request and response, parameter tables
- `docs/api/balance/v2-balance.md` - balance response (documented as 201)
- `docs/API-NOTES.md` - the 401 body verified against the live API, and the note that 422 never occurs
- `tests/Resource/MessagesTest.php` - the exact payload the SDK puts on the wire

Each case holds `request.json` (method, path, headers, body), `expected-response.json` (status and
body) and, when the request stores anything, `expected-messages.json`. Ids generated per request
(`requestId`, `messageIds`, our own message id) are not asserted literally: the contract test only
checks their shape.
