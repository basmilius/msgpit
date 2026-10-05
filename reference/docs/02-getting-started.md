# Getting started

msgpit runs as a container next to your application. Point the provider base URL at it and change
nothing else.

## As a Docksal service

Add this to `.docksal/docksal.yml` in the project that needs it:

```yaml
services:
  msgpit:
    hostname: msgpit
    image: ${MSGPIT_IMAGE:-ghcr.io/axilium/msgpit:1}
    volumes:
      - msgpit_data:/data
    labels:
      - io.docksal.virtual-host=msgpit.${VIRTUAL_HOST},msgpit.${VIRTUAL_HOST}.*
      - io.docksal.virtual-port=8080
      - io.docksal.cert-name=${VIRTUAL_HOST_CERT_NAME:-none}
    environment:
      - MSGPIT_SPRYNG_DLR_URL=http://web/sms-status.php
    healthcheck:
      interval: ${DOCKSAL_CONTAINER_HEALTHCHECK_INTERVAL:-10s}

volumes:
  msgpit_data:
```

`fin up`, and the UI is at `http://msgpit.<project>.docksal.site`. Your application reaches the API
at `http://msgpit:8080` from any other container in the project.

Pin the major tag. Breaking changes to the provider routes or the `/api` contract get a major
version bump, so `:1` keeps working until you decide otherwise.

If you leave out the `volumes` block, captured messages disappear on every `fin project reset`.
That is a legitimate choice for a throwaway catcher; it is just worth knowing you made it.

### Trying a development build

Every push to msgpit's `main` branch publishes a release, and every push to `dev` publishes a
moving `:dev` tag. To point one project at that build without touching the shared config, put this
in `.docksal/docksal-local.env`, which is gitignored:

```dotenv
MSGPIT_IMAGE=ghcr.io/axilium/msgpit:dev
```

Then `fin up` and check the version in the status bar at the bottom of the UI. Remove the line to
go back to the pinned release. `:dev-<sha>` tags are published too, if you need a specific build
rather than the latest one.

## Plain Docker

```bash
docker run -p 8080:8080 -v msgpit_data:/data ghcr.io/axilium/msgpit:1
```

The image runs as an unprivileged user and takes ownership of `/data` on start, so an existing
volume from an older version keeps working.

## Pointing your application at it

Only the base URL changes. Per environment, so production keeps talking to the real provider:

```php
// config/services.php
'spryng' => [
    'base_url' => env('SPRYNG_BASE_URL', 'https://api.spryng.nl/v2'),
    'api_key' => env('SPRYNG_API_KEY'),
],
```

```dotenv
# .env.local
SPRYNG_BASE_URL=http://msgpit:8080/spryng/v2
SPRYNG_API_KEY=anything
```

The key can be anything. msgpit checks that the header the provider expects is present, never what
is in it. Leave it out entirely and you get the provider's real 401, which is how you find out you
forgot to configure it.

Check that the SDK you use accepts a base URL with a path in it. Some do not, and that is worth
knowing before you debug it. Per-provider notes are under Providers.

## Verifying it works

```bash
curl -X POST http://msgpit:8080/spryng/v2/messages \
  -H 'X-Api-Key: anything' -H 'Content-Type: application/json' \
  -d '{"accountReference":"SPNL0000000","channel":"SMS","from":"Acme",
       "body":{"text":"Hello 👋"},"recipients":[{"msisdn":"+31612345678"}]}'
```

You should get a `202` with a `requestId`, and the message should appear in the UI immediately.
Note that it is flagged UCS-2: the emoji costs you 90 characters of capacity.

## Configuration

| Variable | Default | |
|---|---|---|
| `MSGPIT_DB` | `/data/msgpit.sqlite` | SQLite file |
| `MSGPIT_PROVIDERS` | all | Comma-separated provider ids to enable |
| `MSGPIT_MAX_MESSAGES` | `1000` | Older messages are pruned beyond this |
| `MSGPIT_<PROVIDER>_DLR_URL` | - | Delivery-report callback into your app |
| `MSGPIT_<PROVIDER>_DLR_HEADER` | - | Header name to authenticate that callback |
| `MSGPIT_<PROVIDER>_DLR_SECRET` | - | Its value. Set both or neither |
| `MSGPIT_SMTP` | `1` | Set to `0` to run without the SMTP listener |
| `MSGPIT_SMTP_PORT` | `1025` | Port the SMTP listener binds to |
| `MSGPIT_SPAMASSASSIN` | - | `host:port` of a spamd, to score captured mail |

Mount `/data` on a volume if you want captured messages to survive a container restart. Losing
them is a supported outcome, not a failure.

`MSGPIT_IMAGE` is not read by msgpit itself: it is the variable the Docksal snippet above uses so
a project can pin or override the image without editing the shared config.

## Desktop notifications

The tab title carries the unread count, so `(3) msgpit` tells you something arrived without
switching to it.

msgpit can also raise a desktop notification per captured message. Click the bell in the toolbar
to turn it on. Notifications only appear while the tab is in the background, since a message you
are already looking at needs no announcement, and a request to fifty recipients raises one
notification rather than fifty.

**This needs https.** The browser API is only available in a secure context, and Docksal serves
projects over plain http by default. On http the bell explains this and takes you to the https
address of the same page. Docksal's proxy always listens on 443, so
`https://msgpit.<project>.docksal.site` works right away; you will get a certificate warning from
its self-signed certificate, which you can accept. To lose the warning, install Docksal's mkcert
addon and issue a certificate for the host.

## When something is wrong

`fin up` waits for every container in the project to become healthy and fails the whole project if
one of them does not. So if msgpit cannot start, your project will not either. Check it with:

```bash
fin logs msgpit
curl -s http://msgpit:8080/healthz
```

The health endpoint answers `{"status":"ok","version":"..."}`, which also tells you which build is
actually running.
