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

SQLite lives in the `msgpit_data` volume, separate from the PHP database. Stop the
container with `docker compose stop`; bring it back with `docker compose up -d --wait`.
Override host ports with `MSGPIT_HTTP_PORT` and `MSGPIT_SMTP_PORT` when needed.

## Current scope

The first Rust implementation includes Spryng v2 message capture and balance,
SMS segment analysis, one-shot failures and magic recipients, SMTP, MIME parsing,
mail imports and attachment downloads, read state, retention and resumable SSE.
The web inbox supports channel and recipient filters, source inspection and light/dark themes.

This is the starting point for the rebuild. Spryng webhook administration and delivery
reports, other providers, mail authentication, deliverability, link checks and spam
scoring still need porting. Unsupported routes return errors and provider capabilities
report delivery reports as unavailable. See [`docs/rebuild.md`](docs/rebuild.md).

Command Center integration will follow after this container is ready. Command Center
has not been changed by this rebuild.

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
| `GET /api/providers` | Enabled provider capabilities |
| `GET /api/scenarios` | Failure scenarios and magic recipients |
| `POST /api/scenario` | Arm the next valid provider send with `{"scenario":"ServerError"}`; null disarms |
| `GET /api/stream?seq=N` | Replay and live SSE; `Last-Event-ID` also supported |

Message summaries retain the PHP API's camelCase fields. Additional mail detail fields
are `html`, `headerList` and `parts`. SSE includes `message`, `read`, `cleared`, `scenario` and `reset`; reload the
inbox on `reset` when a cursor is outside the retained event window.

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `MSGPIT_HTTP_ADDR` | `0.0.0.0:8080` | HTTP listener inside the container |
| `MSGPIT_SMTP_ADDR` | `0.0.0.0:1025` | SMTP listener inside the container |
| `MSGPIT_DB` | `/data/msgpit-rust.sqlite` | New Rust database; PHP data is not migrated |
| `MSGPIT_WEB_DIR` | `/app/web` in Docker | Built web assets |
| `MSGPIT_MAX_MESSAGES` | `1000` | Retain newest messages, remove associated files |
| `MSGPIT_PROVIDERS` | `spryng` | Comma-separated enabled providers; empty disables provider HTTP routes |
| `RUST_LOG` | `msgpit_server=info,tower_http=info` | Logging filter |

HTTP bodies and SMTP messages are limited to 30 MiB. SMTP sessions have a 60-second
idle timeout, a 128-connection cap and at most 100 recipients per transaction.
The container runs as uid 10001. Its healthcheck tests HTTP and the SMTP greeting.

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
cd web && bun run build
```

Verify the built image, including its bundled UI, with:

```sh
docker compose up --build -d --wait
python3 scripts/smoke.py
```

The smoke test creates tagged SMS, SMTP and import samples and leaves them in the
inbox for inspection. It does not clear existing captures. CI builds and tests the
container on pull requests; it does not publish an image or a release.

## Upstream workflow

`origin` points to `basmilius/msgpit`; `upstream` points to `axilium/msgpit`.
Development is on `feat/rust-rebuild`, leaving the fork's main branch aligned with
upstream. Push a reviewed change to origin, then open a PR targeting `axilium/msgpit`.
