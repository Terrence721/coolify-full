#!/usr/bin/env bash
#
# Stops the dev stack cleanly. Deliberately `compose stop`, not `compose down` - `down`
# destroys and recreates every container on the next `dev-up.sh`, which re-triggers the
# exact WSL2 bind-mount race dev-up.sh exists to work around (see its own header comment).
# `stop` leaves the containers intact so the next start just restarts them - fast, and
# immune to the create-time race entirely.
#
# Usage: ./scripts/dev-down.sh   (or via the "Stop Dev Stack" VSCode task)

set -euo pipefail
cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.yml -f docker-compose.dev.yml"

if [ -f docker/https-proxy/certs/dev.crt ]; then
    COMPOSE="$COMPOSE -f docker-compose.https.yml"
fi

echo "==> Stopping the dev stack..."
$COMPOSE stop

echo ""
echo "==> Done. Containers are stopped, not removed - next dev-up.sh (or workspace reopen) just restarts them."
