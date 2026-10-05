# msgpit

A local catcher for SMS and email, running in its own Docker container.
The Rust server accepts provider HTTP requests and SMTP, stores captured messages in
SQLite and serves the Messages interface ported from Command Center, using
[`@basmilius/desktop-ui`](https://github.com/basmilius/desktop/tree/main/packages/desktop-ui).

This branch rebuilds the PHP proof of concept. Its source, documentation and fixtures
are preserved in [`reference/`](reference/). The Git history remains intact for pull
requests to `axilium/msgpit`.

## Run it

```sh
docker compose up --build -d --wait
```

Open **http://localhost:18080**. SMTP is available at **localhost:11025**.
The Compose ports bind to loopback; containers on the same network use `msgpit:8080`
and `msgpit:1025`. There is no SMTP authentication or TLS.

```sh
curl http://localhost:18080/spryng/v2/messages \
  -H 'X-Api-Key: development' \
  -H 'Content-Type: application/json' \
  -d '{"accountReference":"SPNL0000000","channel":"SMS","from":"Acme","body":{"text":"Hello from msgpit"},"recipients":[{"msisdn":"+31612345678"}]}'
```

The inbox updates over SSE. Use the Import .eml button or drop a file onto the window.
Captured HTML is sandboxed; remote images and scripts are blocked.
SpamAssassin is included and scores SMTP mail and .eml imports automatically.
No second service or rule download is needed at startup.

SQLite lives in the `msgpit_data` volume, separate from the PHP database. Stop the
container with `docker compose stop`; bring it back with `docker compose up -d --wait`.
Override host ports with `MSGPIT_HTTP_PORT` and `MSGPIT_SMTP_PORT` when needed.

## Features

Spryng v2 sends, balance and webhook administration are available, including on-demand
Delivered/Failed callbacks with recorded responses. SMS analysis counts GSM septets,
UCS-2 code units, segments and the characters that force Unicode encoding.

SMTP captures one entry per envelope recipient. Imports keep one entry and include
To, Cc and Bcc recipients. Mail detail includes decoded headers, plain text, a sandboxed
HTML preview, the original .eml download, formatted HTML and MIME source, attachments,
caniemail compatibility, extracted links, SpamAssassin results and a deliverability report.
Sender DNS checks cover SPF, DKIM, DMARC, forward-confirmed reverse DNS and 16 blocklists.
Link probes and sender DNS run only when requested. The bundled SpamAssassin uses local rules
during capture. A scoring failure does not lose the message.

The Command Center interface supports channel and recipient filters, read state,
retention, resumable SSE, multi-file import, themes, browser notifications and built-in
documentation. The reference comparison is in [docs/parity.md](docs/parity.md).

Command Center's own embedded service remains unchanged. Replacing it with an HTTP/SSE
connection is the next task after reviewing this container.

## HTTP API

| Route | Purpose |
| --- | --- |
| `GET /healthz` | Database readiness and server version |
| `GET /api/messages?provider=&channel=&to=&since=` | Newest messages and global unread count |
| `GET /api/messages/{id}` | Message, raw request and mail parts |
| `DELETE /api/messages` | Clear captured messages |
| `POST /api/messages/{id}/read` | Mark one message read |
| `POST /api/messages/read` | Mark every message read |
| `POST /api/messages/import` | Import raw .eml bytes; optional percent-encoded `X-Msgpit-Filename` |
| `GET /api/messages/{id}/parts/{part}` | Download a MIME part or the original .eml |
| `POST /api/messages/{id}/dlr` | Mark delivered/failed; call the configured webhook |
| `POST /api/messages/{id}/links` | Probe extracted URLs on demand |
| `POST /api/messages/{id}/authentication` | Extend the report with sender DNS checks |
| `GET /api/docs` and `GET /api/docs/{slug}` | Built-in documentation |
| `GET /api/providers` | Enabled provider capabilities |
| `GET /api/scenarios` | Failure scenarios and magic recipients |
| `POST /api/scenario` | Arm the next valid provider send with `{"scenario":"ServerError"}`; null disarms |
| `GET /api/stream?seq=N` | Replay and live SSE; `Last-Event-ID` also supported |

Message summaries retain the PHP API's camelCase fields. Mail details also include `text`, `html`, `headerList`, `parts`, `sourcePart`,
`htmlCheck`, `links`, `spam` and `report`. SSE includes `message`, `read`, `cleared`, `scenario` and `reset`; reload the
inbox on `reset` when a cursor is outside the retained event window.

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `MSGPIT_HTTP_ADDR` | `0.0.0.0:8080` | HTTP listener inside the container |
| `MSGPIT_SMTP` | `1` | Set to `0` to disable SMTP |
| `MSGPIT_SMTP_PORT` | `1025` | Legacy SMTP port; overridden by an explicit SMTP address |
| `MSGPIT_SMTP_ADDR` | `0.0.0.0:1025` | SMTP listener inside the container |
| `MSGPIT_DB` | `/data/msgpit-rust.sqlite` | New Rust database; PHP data is not migrated |
| `MSGPIT_WEB_DIR` | `/app/web` in Docker | Built web assets |
| `MSGPIT_MAX_MESSAGES` | `1000` | Retain newest messages, remove associated files |
| `MSGPIT_PROVIDERS` | `spryng` | Comma-separated enabled providers; empty disables provider HTTP routes |
| `MSGPIT_SPRYNG_DLR_URL` | unset | Application webhook called when marking delivery status |
| `MSGPIT_SPRYNG_DLR_HEADER` / `MSGPIT_SPRYNG_DLR_SECRET` | unset | Optional callback authentication; set both |
| `MSGPIT_SPAMASSASSIN` | `local` in Docker | Bundled filter; `off` disables it, or set an external spamd host:port |
| `MSGPIT_DNS` | enabled | `off`, `0`, `false` or `no` disables sender DNS checks |
| `MSGPIT_VERSION` | package version | Version reported by HTTP API |
| `RUST_LOG` | `msgpit_server=info,tower_http=info` | Logging filter |

HTTP bodies and SMTP messages are limited to 30 MiB. SMTP sessions have a 60-second
idle timeout, a 128-connection cap and at most 100 recipients per transaction.
The container runs as uid 10001. Its healthcheck tests HTTP and the SMTP greeting when enabled.
Link checks probe up to 50 URLs with eight concurrent workers and bounded timeouts.
Metadata service and link-local addresses are refused; redirects are reported rather than followed.

## Development

Rust toolchain and Bun are required for local development.

```sh
cd web && bun install && bun run build
cd ..
MSGPIT_DB=./data/msgpit.sqlite cargo run -p msgpit-server
```

For UI development against the Docker server, use `cd web && bun run dev`.
Vite proxies `/api` to `http://127.0.0.1:18080`.

```sh
cargo fmt --all --check
cargo clippy --workspace --all-targets -- -D warnings
cargo test --workspace
cd web && bun run build && bun run test
```

Verify the built image, including its bundled UI, with:

```sh
docker compose up --build -d --wait
python3 scripts/smoke.py
python3 scripts/docker-runtime.py
```

The smoke test creates tagged SMS, SMTP and import samples and leaves them in the
inbox for inspection. It does not clear existing captures. The runtime test uses temporary
containers to check offline startup, automatic scoring, health failures, crash recovery
and graceful shutdown. Run browser coverage with
`cd web && bunx playwright install chromium && bun run test:browser` after starting Docker.

CI runs Rust checks, source-renderer tests, container smoke tests and Playwright against
the bundled UI. Published GitHub releases build amd64/arm64 images in GHCR. The workflow
can also be run manually to publish a branch image. No release is created automatically.
Build locally until the Rust image has been published; existing upstream tags contain PHP.

## Upstream workflow

`origin` points to `basmilius/msgpit`; `upstream` points to `axilium/msgpit`.
Development is on `feat/rust-rebuild`, leaving the fork's main branch aligned with
upstream. Push a reviewed change to origin, then open a PR targeting `axilium/msgpit`.
