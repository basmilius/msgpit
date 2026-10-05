# Rebuild decisions

The container owns message capture, persistence and the web UI. Command Center will
be an HTTP/SSE client. It will not start another embedded catcher or access SQLite.
A single container per project is the initial deployment model. URL project prefixes
from Command Center are not implemented in this first version.

The executable binds both listeners before becoming ready. If a listener fails,
the process exits so Docker can restart it. SIGTERM cancels SMTP sessions and drains
HTTP requests. SQLite work runs on blocking workers, outside Tokio's async workers.
Captures, associated files and events commit in one transaction.

Providers translate HTTP requests into normalized messages and provider-shaped
responses. They perform no I/O. The executable registers concrete implementations.
Spryng send/balance behavior is checked against fixtures preserved from the PHP version.
Its webhook and delivery-report endpoints are deferred and advertised as unavailable.

MIME parsing uses mail-parser; HTTP routing uses Axum. These dependencies replace
proof-of-concept parsers rather than preserving the PHP restriction on packages.
The UI uses the published Desktop UI package and its Tailwind theme.

The new database uses `rust_` tables and a separate default filename. It does not
claim to migrate PHP captures. A migration can be added if keeping old development
messages becomes necessary.

## Follow-up work

- Port Spryng webhook administration and delivery callbacks with fixture coverage.
- Decide which additional provider APIs are needed before adding adapters.
- Port mail reports and opt-in network checks without enabling outbound work during capture.
- Define release and image publishing for the Rust implementation after upstream review.
- Replace Command Center's embedded capture service with connection configuration and HTTP/SSE.
- Decide whether a shared container needs project scopes; keep that decision in the API contract.

## UI source

The shell, sidebar, two toolbars, 340-pixel inbox, mail tabs, SMS analysis and setup
rows follow Command Center's `apps/client/src/shell` and `features/messages`.
`Tabs` and `KeyValueList` are copied from its pending components. The UI uses the
published `@basmilius/desktop-ui` package from `basmilius/desktop`, including its
theme, settings dialog, list rows, segmented controls and confirmation dialogs.

The API adapter replaces Command Center's Electron bridge. Project selection,
assistant actions, delivery reports and mail checks are omitted until their
standalone equivalents exist. The source block uses a selectable `pre` and the
Desktop UI copy button rather than importing Command Center's chat renderer.
