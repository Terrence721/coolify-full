#!/bin/sh
# Generates a self-signed TLS cert for the local-only friendly dev URL
# (https://coolify-full.localhost:8443), and for Vite's own dev-server assets - Vite serves
# from VITE_HOST (see vite.config.js, issue #84's mixed-content fix), which may be a different
# hostname (e.g. a LAN IP), so that also needs to be a SAN or the browser shows a second,
# separate cert warning for a hostname the cert doesn't cover.
# Not committed to git - the private key lives here, regenerated locally on first use. Browsers
# show a "connection not private" warning per distinct origin (self-signed, not from a trusted
# CA) the first time; click through it once per origin and it's remembered after that. See
# docs/command.md.

set -e
cd "$(dirname "$0")"

VITE_HOST=$(grep -E '^VITE_HOST=' ../../.env 2>/dev/null | cut -d= -f2-)

SAN="DNS:coolify-full.localhost,DNS:localhost"
if [ -n "$VITE_HOST" ] && [ "$VITE_HOST" != "localhost" ]; then
    if echo "$VITE_HOST" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
        SAN="$SAN,IP:$VITE_HOST"
    else
        SAN="$SAN,DNS:$VITE_HOST"
    fi
fi

openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout certs/dev.key \
    -out certs/dev.crt \
    -subj "/CN=coolify-full.localhost" \
    -addext "subjectAltName=$SAN"

echo "Generated docker/https-proxy/certs/dev.crt + dev.key (SAN: $SAN)"
