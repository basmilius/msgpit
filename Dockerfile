FROM oven/bun:1.4.0 AS web
WORKDIR /web
COPY web/package.json web/bun.lock ./
RUN bun install --frozen-lockfile
COPY web/ ./
RUN bun run build

FROM rust:1.98-bookworm AS server
WORKDIR /build
COPY Cargo.toml Cargo.lock ./
COPY crates/ ./crates/
COPY data/ ./data/
COPY docs/ ./docs/
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/build/target \
    cargo build --release --locked --bin msgpit-server \
    && cp target/release/msgpit-server /tmp/msgpit-server

FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates && rm -rf /var/lib/apt/lists/*
RUN groupadd --gid 10001 msgpit \
    && useradd --uid 10001 --gid msgpit --no-create-home msgpit \
    && mkdir -p /data /app/web \
    && chown msgpit:msgpit /data
COPY --from=server /tmp/msgpit-server /usr/local/bin/msgpit
COPY --from=web /web/dist/ /app/web/
ENV MSGPIT_DB=/data/msgpit-rust.sqlite \
    MSGPIT_WEB_DIR=/app/web \
    MSGPIT_HTTP_ADDR=0.0.0.0:8080 \
    MSGPIT_SMTP_ADDR=0.0.0.0:1025
USER msgpit
WORKDIR /app
EXPOSE 8080 1025
VOLUME /data
HEALTHCHECK --interval=10s --timeout=4s --start-period=10s --retries=3 CMD ["msgpit", "healthcheck"]
ENTRYPOINT ["msgpit"]
