# Getting started

Build this Rust branch locally until its image is published:

```sh
docker compose up --build -d --wait
```

The browser URL is http://localhost:18080. SMTP listens on localhost:11025.
An application container on the same Docker network uses `msgpit:8080` for HTTP
and `msgpit:1025` for SMTP. The default Compose ports bind to loopback.

## Docksal

First build the local image from this repository:

```sh
docker build -t msgpit:local .
```

Add the service to your application's `.docksal/docksal.yml`:

```yaml
services:
  msgpit:
    image: msgpit:local
    hostname: msgpit
    volumes:
      - msgpit_data:/data
    networks:
      default:
        aliases: [mail, mailpit]
    labels:
      - io.docksal.virtual-host=msgpit.${VIRTUAL_HOST},msgpit.${VIRTUAL_HOST}.*
      - io.docksal.virtual-port=8080
    environment:
      MSGPIT_SPRYNG_DLR_URL: http://web/sms-status.php
volumes:
  msgpit_data:
```

Run `fin up`. Open `http://msgpit.<project>.docksal.site`. Configure applications
with `SPRYNG_BASE_URL=http://msgpit:8080/spryng/v2` and a development API key.
For mail, use `MAILER_DSN=smtp://msgpit:1025` or Laravel's `MAIL_HOST=msgpit`
and `MAIL_PORT=1025`. Disable TLS and authentication.

## Configuration

| Variable | Default | Meaning |
| --- | --- | --- |
| `MSGPIT_DB` | `/data/msgpit-rust.sqlite` | New Rust database |
| `MSGPIT_MAX_MESSAGES` | `1000` | Retain newest messages and remove older parts |
| `MSGPIT_PROVIDERS` | `spryng` | Enabled provider IDs; empty disables HTTP provider routes |
| `MSGPIT_HTTP_ADDR` | `0.0.0.0:8080` | HTTP listener |
| `MSGPIT_SMTP` | `1` | `0` disables SMTP |
| `MSGPIT_SMTP_PORT` | `1025` | SMTP port inside the container |
| `MSGPIT_SMTP_ADDR` | unset | Explicit address overrides the port |
| `MSGPIT_SMTP_HOSTNAME` | `msgpit` | SMTP greeting hostname |
| `MSGPIT_SPRYNG_DLR_URL` | unset | Application delivery webhook |
| `MSGPIT_SPRYNG_DLR_HEADER` / `MSGPIT_SPRYNG_DLR_SECRET` | unset | Optional callback authentication; set both |
| `MSGPIT_SPAMASSASSIN` | `local` in Docker | Bundled filter; `off` disables it, or set an external spamd host:port |
| `MSGPIT_DNS` | enabled | `off`, `0`, `false` or `no` disables sender DNS |
| `MSGPIT_VERSION` | package version | Reported version |

`MSGPIT_HTTP_PORT` and `MSGPIT_SMTP_PORT` in the repository's Compose command
environment change the mapped host ports. They are not passed into the container.
To change a container listener, set its service environment and update the mapping.

Use a volume to keep captures across restarts. The image runs as uid 10001 and a
bind-mounted directory must be writable for that uid. The PHP database is retained
separately and is not migrated.

## Releases

Published GitHub releases build amd64 and arm64 images in GHCR under the repository
owner. A manual workflow run publishes a branch image. Existing upstream image tags
still contain the PHP implementation until the Rust branch is released. Pin a specific
Rust release after it has been published.

The Setup screen shows connection examples. `/healthz` and `/api/providers` report
the running version and enabled listeners.
