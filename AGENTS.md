# Msgpit rebuild

The active application is a standalone Rust server and a React web UI using
`@basmilius/desktop-ui`. The PHP proof of concept is in `reference/`; its instructions
apply only there. Read it for provider behavior and fixtures, rather than extending it.

Keep provider translation pure. Register providers in `main.rs`; storage, SMTP and the
HTTP API depend on the provider contract, never a concrete provider. Capture one message
per recipient, with a shared batch id. Imported .eml files deliberately create one row.

No real message delivery. Mask credentials before storing raw HTTP requests. Render
captured HTML inside an iframe without script permissions and block remote resources.

Use English for code and documentation. Keep comments for reasons and external constraints.

Check changes with `cargo fmt --all --check`, `cargo clippy --workspace --all-targets -- -D warnings`,
`cargo test --workspace`, and `cd web && bun run build`.

For runtime verification, build and start the actual container with
`docker compose up --build -d --wait`, then run `python3 scripts/smoke.py`.
Leave it running for the user at http://localhost:18080 and mention the URL.
Never use `docker compose down -v` without explicit permission; the volume holds captured messages.
