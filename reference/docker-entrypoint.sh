#!/bin/sh
set -e

# The data volume outlives the image: it may have been created by an older root-only version, or
# be a host bind mount owned by whoever runs Docker. Take ownership while we still can, then drop
# to an unprivileged user for the server itself.
if [ "$(id -u)" = '0' ]; then
    chown -R www-data:www-data /data 2>/dev/null || true

    exec su-exec www-data "$0" "$@"
fi

# The SMTP listener runs beside the web server. It is restarted if it dies, because a container
# that still answers HTTP while quietly accepting no mail is the worst of both worlds.
if [ "${MSGPIT_SMTP:-1}" != "0" ]; then
    (
        while true; do
            php /app/bin/smtpd.php || echo "smtp: listener exited, restarting" >&2
            sleep 1
        done
    ) &
fi

exec "$@"
