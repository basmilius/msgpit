# Rebuild decisions

The container owns capture, SQLite and the web UI. Command Center will connect over
HTTP/SSE after the container review. Its embedded service has not been changed.

One container per project keeps provider URLs identical to upstream Msgpit. Providers
translate requests and produce normalized messages and responses without I/O. Storage,
SMTP and HTTP depend on that contract. The current upstream provider is Spryng.

Axum handles HTTP, mail-parser handles MIME and mail-auth verifies SPF and DKIM. DNS
uses the container resolver and TTL caching. Deliverability content checks run locally;
sender DNS and link probes run only on explicit requests. SpamAssassin and its packaged
rules are included in the image. It runs local content checks during capture; a scoring failure never prevents capture. An external
spamd can be selected with `MSGPIT_SPAMASSASSIN=host:port`, or disabled with `off`.
Native `cargo run` defaults to disabled unless this variable is set.

The server binds enabled listeners before reporting readiness. A bind failure exits
and lets Docker restart it. Readiness waits for the bundled spamd to answer PING.
Msgpit supervises spamd. An unexpected daemon exit stops the server so Docker can
restart the container. Tini
reaps child processes. SIGTERM stops SMTP sessions, drains HTTP and stops spamd
and its workers. The healthcheck checks HTTP, enabled SMTP and bundled spamd.
SQLite runs on blocking workers. Messages, parts and events commit together.

The database uses `rust_` tables and a separate default filename. The PHP proof of
concept and its fixtures remain in `reference/`. No development captures are migrated
or deleted by the rebuild.

## UI source

The shell, sidebar, two toolbars, 340-pixel inbox, mail tabs, SMS analysis, delivery
panel and setup rows are ported from Command Center. `Tabs` and `KeyValueList` retain
its pending components. The UI uses the published `@basmilius/desktop-ui` package,
including its theme, list rows, settings dialog, controls and confirmation dialogs.
The HTTP adapter replaces the Electron bridge.

The formatted HTML and raw MIME renderers come from upstream Msgpit. Their original
30 tests verify escaping and source preservation. Documentation uses react-markdown
with raw HTML disabled. Captured message HTML runs in a separate sandboxed frame.

See [parity.md](parity.md) for the implemented upstream features, verification and
deliberate differences. The remaining application work is replacing Command Center's
embedded catcher with connection settings and this HTTP/SSE client.
