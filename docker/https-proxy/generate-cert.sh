#!/bin/sh
# Generates a self-signed TLS cert for the local-only friendly dev URL
# (https://coolify-full.localhost:8443). Not committed to git - the private key lives here,
# regenerated locally on first use. Browsers show one "connection not private" warning the
# first time; click through it once and it's remembered after that. See docs/command.md.

set -e
cd "$(dirname "$0")"

openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout certs/dev.key \
    -out certs/dev.crt \
    -subj "/CN=coolify-full.localhost" \
    -addext "subjectAltName=DNS:coolify-full.localhost"

echo "Generated docker/https-proxy/certs/dev.crt + dev.key"
