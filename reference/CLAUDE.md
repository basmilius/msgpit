# msgpit

Local catcher for outgoing SMS, push and other messages - Mailpit, but for message provider APIs.
Runs as a service in Docksal projects. Apps point their provider base URL to msgpit instead of the
real provider; msgpit accepts the request, stores it, returns a realistic provider response and shows
everything in a web UI.

## Goals

- Drop-in: production code stays identical, only the provider base URL differs per environment.
- Provider-agnostic core; every provider is a plugin. Adding a provider must never require core changes.
- Make message costs and edge cases visible locally (segments, encoding, delivery reports, failures).
- Tiny, dependency-free image that is trivial to add to any Docksal project.

## Non-goals

- No real delivery, ever. msgpit reaches outside in exactly three cases: delivery-report callbacks
  to the app, the link check, and the DNS lookups behind the authentication checks. The last two
  only run when someone presses the button.
- No credential validation (only check that auth has the right *shape*).
- No inbound messages, no multi-user, no auth on the UI, no persistence guarantees.
- No full API coverage per provider: only the endpoints our apps actually use.

## Tech stack and constraints

- PHP 8.3, `declare(strict_types=1)` everywhere, `final` classes by default, `readonly` where possible.
- No framework. No runtime Composer dependencies.
- Runtime must NOT require `vendor/`: `public/index.php` registers its own PSR-4 autoloader for `src/`.
  Composer is only used for dev tooling (PHPUnit, PHPStan). This keeps the image small and allows
  mounting the source over `/app` during development.
- Server: PHP built-in server, `PHP_CLI_SERVER_WORKERS=16 php -S 0.0.0.0:8080 -t public public/index.php`.
  Each open SSE connection holds a worker for as long as the tab is open, hence the headroom.
- Storage: SQLite via PDO. DB file at `$MSGPIT_DB` (default `/data/msgpit.sqlite`). Schema is created on
  boot if missing. Losing data on container reset is acceptable.
- UI: vanilla JS/CSS in `public/ui/`, no build step. Live updates via SSE (`GET /api/stream`),
  so a captured message shows up instantly. No websocket: we only push server to client, and
  hand-rolling RFC 6455 framing would mean reimplementing a library. Polling is the fallback
  when the stream drops.
- Base image: `php:8.3-cli-alpine` with `pdo_sqlite`. Multi-arch (amd64 + arm64).

## Mail

msgpit also catches SMTP, so a project has one place for everything it sends rather than a mail
catcher beside a message catcher. It is meant to replace Mailpit in our projects, not to compete
with it: no POP3, no link checking, no Outlook compatibility report.

- `Smtp\Session` is the protocol as a state machine, with no sockets in it, so the whole dialogue
  is testable without opening a port. `Smtp\Server` adds the sockets and selects over them; PHP
  here has no pcntl, so connections are multiplexed rather than forked.
- **We advertise neither STARTTLS nor AUTH.** Clients only use what the server offers, and every
  client we care about talks plain when nothing else is on the table. This only ever listens inside
  a development network.
- `Mime\Parser` handles what mail clients send, not two decades of broken mail from the internet.
  It stays small because PHP already does the hard parts: `iconv_mime_decode_headers()` for folding
  and RFC 2047, `quoted_printable_decode()` and `base64_decode()` for transfer encodings, `iconv()`
  for charsets. Never unfold headers yourself before decoding: the whitespace between two
  encoded-words has to disappear rather than become a space, and that is how a subject gets mangled.
- SMTP is **not** a provider. The `Provider` contract is HTTP routes and a listener does not fit in
  it, so mail is core: `provider` is `smtp` and the channel is `email`. Do not invent a fake
  provider for it.
- One message per recipient, as everywhere else, and the **envelope** decides who those are, not
  the To header. That is how delivery works and the only way a Bcc shows up at all.
- MIME parts live in their own table with the content as a BLOB, and pruning takes them along:
  attachments are the bulk of the database.
- The listener is a second process started by `docker-entrypoint.sh`, which restarts it if it dies.
  Both processes write the same SQLite file, hence WAL mode and a busy timeout. The healthcheck
  checks both ports, because a container that answers HTTP while silently accepting no mail is the
  worst of both worlds.
- Docksal projects reach it through the network aliases `mail` and `mailpit`, so the sendmail
  configuration that Docksal's cli image ships (`msmtp ... --host=mail --port=1025`) needs no change.

### Imported .eml files

A `.eml` dragged onto the UI goes to `POST /api/messages/import` and is stored like any other mail,
so the spam score, html check and link check work on a message that was already sent elsewhere.

- **An import is not a delivery, and the UI has to say so.** It is stored under the provider
  `import` rather than `smtp`, carries `meta.imported`, and shows an "imported" tag in the list and
  a line in the detail explaining what is different.
- **One row per import, whatever the number of addresses.** This is the one place that breaks
  "one stored message per recipient", and deliberately: that rule exists because a send to three
  people is three deliveries that can each fail on their own. An import already arrived, once, so
  three identical rows would be noise. The addresses are joined into `to` rather than dropped, so
  the recipient filter still finds the message: it matches on a substring.
- There is no envelope, because nobody delivered the file. Recipients come from `To`, `Cc` and
  `Bcc`, the sender from `Return-Path` or `From`, and `envelopeSender`/`envelopeRecipients` stay
  **absent** rather than being filled with those. Reporting them would claim a delivery that never
  happened, and a Bcc that only the real envelope knew about is simply not recoverable.
- The file goes up as raw bytes. Re-encoding it would change the very thing the checks are asked to
  judge. The filename travels percent encoded in `X-Msgpit-Filename`, because a header carries
  latin-1 and mail files are named in Dutch.
- The drop target is the whole window: a file coming out of a mail client lands wherever the cursor
  is, and hunting for a rectangle is not an improvement.
- **`preventDefault()` on every dragover, not only on drags that announce `Files`.** A message
  dragged out of macOS Mail is a file promise, and the drag does not always advertise a file, so
  testing the types first means the browser opens the message in a new tab and the page is gone.
  By the time the drop tells us what it carries, objecting is too late.
- **A drag straight out of macOS Mail carries no message.** It puts `message:<message-id>` on the
  drag as a `text/uri-list`: a pointer into Mail's own store, which only Mail can resolve. Reading
  the drop more carefully will not help, so do not try again. The drop names that case
  specifically, and the **Import .eml** button exists because of it. A drop that carries nothing
  also prints its types and values, which is the only way to tell "this client cannot" apart from
  "we are reading it wrong".

### HTML check

`Mime\HtmlCheck` scores a message's html against the caniemail data bundled at
`data/caniemail.json` (MIT, Rémi Parmentier). It collects elements, attributes and css property
names with `DOMDocument`, maps them to caniemail slugs (`html-table`, `css-margin`) and counts the
verdicts per client version.

- **Bundled, not fetched.** msgpit must work offline, so `bin/update-caniemail.php` refreshes the
  file and the result is committed. Never fetch it at runtime.
- Every feature counts once, however often it occurs: weighting by occurrence would flatter a
  message that repeats one safe property.
- An "unknown" verdict counts for neither side.
- Worked out per request rather than stored, because the data is refreshed now and then and a
  score from six months ago would be quietly wrong.
- Tests run against a small invented dataset in `tests/fixtures/caniemail/`, so they do not move
  when caniemail publishes new measurements. One test reads the bundled file to prove its shape.

### Headers

The detail response carries **every** header, read back from the stored message rather than from
the handful kept in `meta`. That is deliberate: nothing has to be duplicated at capture time, and
a message stored before we cared about some header still shows it. `Mime\Parser::headers()` does
the folding and the decoding.

Mail analysis lives here, so do not trim the list: a missing `Date`, a `Return-Path` that
disagrees with `From`, an `Auto-Submitted` that stops an auto-responder. A `Bcc` is visible here
and nowhere else.

### The preview iframe

The html as the recipient sees it, in a sandboxed iframe: no scripts, no forms, its own origin.

A stylesheet of our own goes in ahead of the message, setting a system font stack and a base size.
Without it the browser falls back to Times, which no mail client does: every one of them applies a
default of its own, so Times is the one thing the message will certainly not look like anywhere.
It is a starting point, not an override; anything the message says about type wins.

### The html source view

`public/ui/htmlsource.js` indents and colours the html of a message. A tokenizer, not a syntax
highlighting library: it is one language, and Shiki would bring a bundler, a WASM regex engine and
a grammar bundle to a project that has no build step and has to work offline.

- Indentation is the larger half of the job. Mail html arrives as one line.
- Block elements get their own line and open a level; inline elements stay in the text, because
  breaking those apart changes how a sentence reads.
- Build the coloured tag from its parts, never by chaining replaces over the escaped string: the
  second pass then matches the class attribute of the span the first pass inserted.
- Everything is escaped, text included. This is the source of a captured message, not markup we
  trust.

### The source tab

One tab, two views: the message as it arrived and the html the sender wrote. They were separate
tabs, which asked the reader to know in advance which of the two held the line they were after.
The switch that picks the view sits next to the one that changes how it is drawn, and the second
is deliberately quieter than the first.

Tab order runs from what the message is to what is wrong with it: Preview, Source, Headers, then
the verdicts with Deliverability first, since it draws on the three behind it.

### The raw view

`public/ui/rawmessage.js` lays a captured message out by structure: headers apart from bodies,
boundaries marked, base64 folded behind its size.

Deliberately not a syntax highlighter. The problem with a raw message is not syntax but shape: one
attachment means a single base64 line of fifteen thousand characters, and no colouring fixes that.
Folding it does. A highlighter like Shiki would also mean a build step and a bundled grammar,
which this project does not have and does not want.

### Deliverability report

`Mail\Report` scores a captured message out of ten: one `Check` class per question, each returning
a `Finding` with a status, a penalty and the evidence behind it. Adding a check means one class and
one line in `Report::CHECKS`.

- **A check that cannot apply is skipped, not failed, and skipped findings are left out of the sum.**
  A mail we caught ourselves never travelled: no sending server, no SPF result, no signature. Marking
  every test message down for that would turn the number into noise. The UI says how many were
  skipped and why.
- **Authentication is read, not recomputed.** An imported `.eml` carries `Authentication-Results` and
  `Received-SPF` from the server that really received it. That server had the sending IP and the key
  as it was at the time; we have a file that a mail client re-encoded and a selector that may since
  have rotated. Verifying again here fails messages that were accepted, which is worse than useless.
- The evidence is the product, not the score. "No List-Unsubscribe" is an opinion; the headers we did
  read are a fact, and only the second one tells you where to look. Every finding carries its own.
- **Never present it as a prediction.** Real filters weigh reputation and sending history that
  nothing local can see. Same caution as the spam score, for the same reason.
- Worked out per request like the html check, never stored: it leans on the spam score and on the
  caniemail data, and both move underneath it.

### DKIM verification

`Mail\Dkim` verifies a signature itself: canonicalisation, body hash and `openssl_verify` against
the key from DNS. Written rather than pulled in, because the runtime may not require `vendor/`, and
with explicit permission: this is the exception to "do not reimplement a package", not a precedent.

- **It is the fallback, not the answer.** When a message carries `Authentication-Results`, that is
  what the report shows. Our own verification is for a signature with no verdict attached: a `.eml`
  out of a Sent folder, or mail caught on the way out. It answers "does my sending setup sign
  correctly", not "was this message accepted".
- **The body hash is checked first and gets its own reason.** It is the failure that actually
  happens: a mail client re-encodes on export, one byte moves, and the hash is gone. Reporting that
  as a bad signature would send someone hunting for a key problem that is not there.
- Supports `rsa-sha256` with all four canonicalisation combinations. Everything else, `ed25519`
  included, reports `unsupported` with the reason. Half an implementation that guesses is worse than
  one that says what it cannot do.
- `x=` in the past fails. A `t=` in the future and a key in test mode are notes, not verdicts:
  neither makes a signature invalid.
- The verifier does no DNS of its own; it takes a `KeyLookup`. That keeps the crypto testable
  without a network and the resolver replaceable.

### DNS

`Core\Dns` is the only place msgpit asks the network something that is not an http request, and it
exists because SPF, DMARC and DKIM cannot be judged without it: the answer lives in the sender's
zone and nowhere else.

- **Runs when the Deliverability tab is opened, never when a message is.** A resolver that is slow or
  gone would otherwise make reading your own post slow or gone, and most of the time nobody is
  asking the question. The detail response is built without DNS; the tab fetches the full report
  again with it, once per message.
- `MSGPIT_DNS=off` switches it off; the checks then report as not applicable, which is a supported
  way to work and not a failure.
- Answers are cached with the TTL of the record, in the generic `cache` table. It outlives the
  request on purpose: twenty messages from one domain then cost one lookup between them.
- **The names come out of a captured message, so a sender chooses them.** Anything that walks a
  chain of them has to cap how far it follows; SPF's ten-lookup limit is that cap, and it is a
  safety measure rather than a detail of the spec.
- `dns_get_record()` cannot be pointed at a specific nameserver, whatever it looks like: `$authns`
  is an output. Aiming at an authoritative server would mean writing a resolver over UDP, and
  measured lookups run at 17 to 56 ms through the container's resolver, so the win is in the cache.
- **A blocklist answer is never just yes.** The meaning is in the last octet and it differs per
  list: the code that means "spam source" on one means "known good" on another, and Spamhaus's
  policy range only says an address should not be sending mail directly, which is true of every
  home connection. Read the code against the list that gave it, or the report accuses people of
  things they did not do.
- Lists answer in `127.255.255.0/24` to refuse a query, which is what they do for anything arriving
  through a public resolver. Reading that as a listing is exactly backwards; when every list refuses,
  say the resolver is the problem.
- DMARC needs the organisational domain. RFC 7489 says to find it with the public suffix list; we
  walk up a label at a time and stop while two are left. Same record for every real zone, a lookup
  or two more, and no 200 kB list to keep fresh. The case it gets wrong is a public suffix that
  publishes DMARC of its own, and none do.

### Link check

`Mime\Links` finds the unique urls in a message (anchors, images, css `url()`, and bare urls in the
body text); `Core\LinkChecker` fetches them.

- **Never automatic.** This is the only thing msgpit does that leaves the development network, and
  it runs only when the user presses the button. Links in mail carry one-shot tokens: fetching a
  password reset or an unsubscribe link can spend it, and a tracking pixel counts the fetch as a
  read. The UI says so before the button, and that warning stays.
- HEAD first, GET when the server refuses it. Redirects are reported, not followed: a redirect
  chain is what you want to see, and not following it also means no second host to validate.
- **Link-local addresses are refused, and the decision is made on the resolved address.** That is
  where cloud metadata services live, and they hand out credentials to whatever asks. A hostname
  denylist does not do it: a name that resolves to 169.254.169.254 is the whole trick, and so is a
  trailing dot. The rest of the private network stays reachable on purpose, because checking that
  a template built the right url for `http://web` is one of the reasons this exists. Only http and
  https are fetched.
- Results are not stored. They are about the world right now, not about the message.

### Spam scoring

`MSGPIT_SPAMASSASSIN` (`host:port`) points at a spamd, the same spelling Mailpit uses. The protocol
is a REPORT request and a reply with the score and the rules; nothing is installed in our image.

- **Best effort, always.** A daemon that is down, slow or absent means no score, never a failed
  capture. A catcher that drops mail because a side service is unhappy is worse than one that shows
  no number.
- The rule table is the point, not the score: it names which line of a template is costing points.
  A wrapped description belongs to the rule above it, which is the one parsing subtlety here.
- A score computed in isolation has no `Received` headers, no SPF or DKIM and no reputation, so it
  says something about content and nothing about what a real filter would decide. Do not present
  it as a prediction.

## Architecture

```
src/
  Core/       Router, Storage, Message, Channel, Segments, DlrDispatcher, Scenario, ProviderRegistry
  Http/       Request, Response, OutgoingRequest (thin value objects, no PSR-7 dependency)
  Api/        UI/test API controllers
  Provider/
    Spryng/        SpryngProvider.php
    MessageBird/   MessageBirdProvider.php
    Fcm/           FcmProvider.php
providers.php     registry: list of provider classes
public/
  index.php       front controller + autoloader
  ui/             index.html, app.js, style.css
tests/
  Unit/
  Contract/       generic contract test, runs for every provider
  fixtures/<provider>/<case>/  request.json, expected-response.json, expected-messages.json
```

### Rules

1. **Core never references a concrete provider.** Core only knows the `Provider` interface and the
   optional capability interfaces.
2. **Every provider lives under its own route prefix** equal to `Provider::id()`:
   `http://msgpit:8080/spryng/...`, `http://msgpit:8080/twilio/...`. Paths after the prefix mirror the
   real provider API exactly.
3. **Channel belongs to the message, not the provider.** One provider can deliver several channels
   (e.g. CM.com and Twilio: sms + whatsapp).
4. **Always store the raw request** (method, path, headers with secrets masked, body) next to the
   normalized message.
5. **One stored message per recipient.** A request with 3 recipients produces 3 messages sharing one
   `batchId`.

### Domain model

`Message`: `id`, `batchId`, `provider`, `channel` (`sms|push|whatsapp|...`), `from`, `to`, `body`,
`meta` (JSON: push title/data, template info, provider extras), `providerRef` (the id returned to the
app), `status` (`accepted|delivered|failed|...`), `encoding` and `segments` (sms only, computed by
core), `rawRequest`, `createdAt`, `readAt`.

A message is unread until it is opened in the UI. The read state is server-side, so it is shared
between tabs, and it is independent of `status`: marking a message delivered does not make it read.
The count appears in the tab title as `(3) msgpit`.

Desktop notifications are opt-in through the bell in the toolbar, fire only while the tab is
hidden, and collapse a multi-recipient request into one notification. The Notifications API needs
a secure context and Docksal serves http by default, so on http the button says so and offers the
https address rather than failing silently. Keep that fallback: it is the difference between a
feature that looks broken and one that explains itself.

The UI's top bar is dark in both light and dark themes; the workspace below it is the lighter
surface. Colours come from the custom properties at the top of `style.css`, never hardcoded.

**Only content can be selected, never the furniture.** The interface is chrome and the message is
the content: dragging across the message list used to leave half of it highlighted, which looks
broken and helps nobody, because nobody wants to copy a menu. Selection is given back to the dumps
and to the message itself: `pre`, `code`, the raw message, header values, the plain text body, the
reference pages and the form fields. The preview iframe is a document of its own and was never
covered by this. A new panel that shows a dump needs saying so, or its content cannot be copied.

### Provider contract

```php
interface Provider
{
    /** Stable id, also the route prefix. Lowercase, a-z0-9 only. */
    public function id(): string;

    /** @return list<Route> method + path pattern + handler */
    public function routes(): array;
}

// Handler signature: fn(Request $request, array $params): Capture
final class Capture
{
    /** @param list<Message> $messages Normalized, one per recipient. May be empty (e.g. token endpoints). */
    public function __construct(
        public readonly array $messages,
        public readonly Response $response,
    ) {}
}

// Optional capabilities: implement only what the provider supports.
interface SupportsDeliveryReports
{
    /** Build the callback request the real provider would send. Null if no callback URL is known. */
    public function deliveryReport(Message $message, DeliveryStatus $status): ?OutgoingRequest;
}

interface SupportsErrorScenarios
{
    /** Provider-specific error response for a generic scenario. */
    public function errorResponse(Scenario $scenario): Response;
}
```

Providers are pure translators: parse request, validate required fields and auth shape, map to
`Message` objects, build the provider-shaped response. They do not touch storage or do HTTP calls.

## Core features

- **Segments** (`Core/Segments`): detect GSM-7 vs UCS-2 (including the GSM-7 extension table, which
  counts double) and compute segments: 160/153 for GSM-7, 70/67 for UCS-2. Show per message in the UI and
  highlight the characters that force UCS-2. Must be thoroughly unit tested.
- **Delivery reports**: UI action "delivered" / "failed" per message. Core asks the provider for the
  callback request and `DlrDispatcher` sends it. Callback URL comes from the original request if the
  provider supports that (e.g. MessageBird `reportUrl`), otherwise from `MSGPIT_<PROVIDER>_DLR_URL`.
  Store and show the callback response status/body in the UI.
- **Error scenarios** (`Core/Scenario`): generic scenarios `InvalidNumber`, `Unauthorized`,
  `RateLimited`, `ServerError`. Triggered by magic recipient numbers (`+31600000001` invalid number,
  `+31600000002` server error, ...; list defined in one place in core) or by a one-shot UI toggle
  "next request fails with X". Only applies to providers implementing `SupportsErrorScenarios`.
- **Auth shape check**: provider checks the expected auth mechanism is present (Bearer, Basic,
  AccessKey header, token in body). Missing auth returns the provider's real 401 response.

## HTTP API

| Route | Purpose |
|---|---|
| `/{provider}/...` | Emulated provider endpoints |
| `GET /` | UI |
| `GET /api/messages?provider=&channel=&to=&since=` | List messages (newest first) |
| `GET /api/messages/{id}` | Message detail incl. raw request and DLR history |
| `DELETE /api/messages` | Clear all |
| `POST /api/messages/import` | Import a raw `.eml` (body is the file) |
| `POST /api/messages/{id}/authentication` | Rebuild the report with the DNS checks. Asks DNS |
| `POST /api/messages/{id}/read` | Mark one message read |
| `POST /api/messages/read` | Mark everything read |
| `POST /api/messages/{id}/dlr` | `{"status":"delivered"}` send delivery report |
| `POST /api/scenario` | `{"scenario":"ServerError"}` one-shot failure for next provider request |
| `GET /api/providers` | Enabled providers and their capabilities |
| `POST /api/messages/{id}/links` | Check the links in a message. Reaches the internet |
| `GET /api/scenarios` | Scenario catalogue with the magic numbers, read from the code |
| `GET /api/docs` and `GET /api/docs/{slug}` | Reference pages from `docs/` as Markdown |
| `GET /api/stream` | SSE stream of new messages and status changes |
| `GET /healthz` | Health check |

The `/api` routes are also meant for integration tests in consuming projects (assert that a message
was sent, then clear).

## Configuration (env)

| Variable | Default | |
|---|---|---|
| `MSGPIT_DB` | `/data/msgpit.sqlite` | SQLite path |
| `MSGPIT_PROVIDERS` | all | Comma-separated provider ids to enable |
| `MSGPIT_MAX_MESSAGES` | `1000` | Oldest messages are pruned beyond this |
| `MSGPIT_<PROVIDER>_DLR_URL` | - | Callback URL for delivery reports, e.g. `http://web/sms-status.php` |
| `MSGPIT_<PROVIDER>_DLR_HEADER` | - | Header name authenticating that callback |
| `MSGPIT_<PROVIDER>_DLR_SECRET` | - | Its value. Both or neither |
| `MSGPIT_DNS` | on | `off` disables every DNS lookup, for working offline |

## Adding a provider (checklist)

1. Create `src/Provider/<Name>/<Name>Provider.php` implementing `Provider` (+ capabilities if supported),
   and `src/Provider/<Name>/CLAUDE.md` documenting the API as described under "Providers".
2. Implement only the endpoints our apps use; mirror path, status codes, headers and body shape of the
   real API.
3. Add fixtures in `tests/fixtures/<id>/<case>/` based on the official API docs. Put the source URL of
   the docs in `tests/fixtures/<id>/README.md`.
4. Register the class in `providers.php`.
5. Run `fin exec composer test`: the contract test picks up the new provider automatically.
6. Document the base URL and any SDK caveats (e.g. SDK does not allow a base URL with a path) in
   `README.md` under "Providers".

## Development (Docksal)

This repo is itself a Docksal project: `cli` for tooling, `msgpit` built from the repo `Dockerfile`
with the source mounted, so changes are live without rebuilding.

```bash
fin up                          # UI at http://msgpit.docksal.site
fin exec composer install
fin exec composer test          # PHPUnit
fin exec composer stan          # PHPStan (level max)
fin config                      # show merged Docksal config when debugging
```

Manual testing: send requests with curl from the `cli` container to `http://msgpit:8080/<provider>/...`.

## Testing

- Unit tests for core (Segments, Router, Storage, Scenario, Docs).
- `composer test` runs PHPUnit and the Node tests for the Markdown renderer.
- Contract test (`tests/Contract`) iterates over all registered providers and all fixture cases:
  send request through the router, assert response status/body and the normalized messages.
- Every bug fix in a provider gets a fixture case that reproduces it.
- Tests run against an in-memory SQLite database.

## Release

Image: `ghcr.io/axilium/msgpit`, built for amd64 and arm64.

- **Every push to `main` releases.** `.github/next-version.sh` derives the version from the
  conventional commits since the last `v*` tag: a breaking change (`!` or `BREAKING CHANGE`) bumps
  major, `feat:` bumps minor, anything else bumps patch. The workflow then tags the commit, creates
  the GitHub release, and pushes `X.Y.Z`, `X.Y`, `X` and `latest`.
- **Every push to `dev`** publishes `:dev` and `:dev-<sha>`. No tag, no release.
- Nothing is versioned by hand. Changing how the version is decided means changing that script, and
  `tests/Shell/next-version.test.sh` runs the real script against throwaway repositories.
- Consuming projects pin the major tag (`:1`) through `${MSGPIT_IMAGE:-...}`, so a project can
  point at `:dev` from `docksal-local.env` without touching shared config.
- Distribution is a yml snippet in the README, deliberately not a Docksal addon: `fin addon install`
  only reads from a hardcoded `master` branch on raw.githubusercontent.com, which is more machinery
  than a ten line service block deserves.

### The image

- Runs as `www-data`. `docker-entrypoint.sh` starts as root only to take ownership of `/data`,
  then drops privileges with `su-exec`. This is what lets an existing volume from an older,
  root-only version keep working, and it is why there is no `USER` line in the Dockerfile.
- Ships `docs/`, because the UI serves the reference pages from it. Forgetting this leaves the
  Reference section empty in the published image while it works fine locally, where the source is
  mounted over `/app`.
- Has a `HEALTHCHECK`. `fin up` fails the **whole project** if any container is unhealthy, so keep
  it fast and give it a start period.
- CI builds the image and exercises it without the source mounted, which is the one thing local
  development never covers.

## Documentation

Reference documentation lives in `docs/` as plain Markdown, numbered for reading order
(`01-why.md`, `02-getting-started.md`, ...). The same files are served in the UI under
**Reference**, rendered by `public/ui/markdown.js`.

- One source, two audiences: readable on GitHub and in an editor, rendered in the app.
- Write for someone using msgpit, not for someone maintaining it. Maintenance notes belong in
  this file or in a provider's own CLAUDE.md.
- **Never type out a value that lives in code.** Anything the application already knows (the magic
  numbers, provider capabilities) goes in through a `<!-- placeholder -->` filled from the API, so
  the docs cannot drift.
- The renderer handles only the syntax those files use: headings, paragraphs, unordered lists,
  tables, fenced code, inline code, bold, italic and links. Adding syntax means extending
  `markdown.js` and its tests. Do not reach for a Markdown library.
- `tests/Js/markdown.test.mjs` renders every page and fails on leftover Markdown, so a page using
  unsupported syntax is caught.

## Conventions

- Code, comments, commit messages and docs in English.
- PSR-12 formatting. Small classes, no static state except the registry.
- In docs and UI texts use "-" instead of an em dash.
- Keep the image small: no extra system packages without a clear reason.

### Comments

Keep comments compact. A comment earns its place by explaining *why*, not by restating what
the code already says. One line where one line does. No doc blocks that list the parameters
the signature already declares, no section banners, no commented-out code.

### Third-party code

Avoid external packages when we can do without, but do not reimplement a whole package
either. If a dependency looks unavoidable, stop and ask instead of writing our own version
of it. This is about scope, not pride: a narrow helper we fully control beats both a
framework and a half-finished clone of one.

## Do not

- Add runtime Composer dependencies or a framework.
- Reference concrete providers from core.
- Validate credential values, or make an outbound call other than a delivery-report callback or a
  link check the user asked for.
- Log or display secrets unmasked (mask `Authorization`, API keys and tokens in stored raw requests).
- Reimplement a third-party library ourselves to avoid adding it. Ask first.

## Providers

**Every provider has its own `CLAUDE.md`** next to its class, documenting that provider's API:
base URL, authentication, the endpoints we implement with their status codes, request and response
shapes, error envelopes, delivery reports and any quirks worth remembering. The root CLAUDE.md
only points at it. Keep it current when the provider changes.


### Spryng

We implement Spryng API **version 2**, not version 1. See `src/Provider/Spryng/CLAUDE.md` for the
API details and quirks. Reference material (SDK and docs) lives outside this repo at
`~/Projects/spryng-v2-api`; the v2 API is poorly documented online.
