#!/usr/bin/env bash
#
# Sets up the local dev database for coolify-full: starts the postgres
# (and redis) dev containers from docker-compose.yml + docker-compose.dev.yml,
# waits for postgres to report healthy, then runs migrations inside the
# coolify app container as www-data (matching scripts/run's db:reset task).
#
# docker-compose.dev.yml is an override fragment (no images of its own) —
# it must be combined with the base docker-compose.yml, which is what
# `spin` does under the hood.
#
# Usage:
#   scripts/setup-dev-database.sh            # start db + migrate
#   scripts/setup-dev-database.sh --fresh    # migrate:fresh (drops all tables first)
#   scripts/setup-dev-database.sh --seed     # also run seeders
#   scripts/setup-dev-database.sh --fresh --seed
#
# Requires Docker Desktop running. Does not require the `spin` CLI —
# uses `docker compose` directly against both compose files.

set -euo pipefail

COMPOSE_ARGS=(-f docker-compose.yml -f docker-compose.dev.yml)
FRESH=false
SEED=false

for arg in "$@"; do
    case "$arg" in
        --fresh) FRESH=true ;;
        --seed) SEED=true ;;
        *)
            echo "Unknown option: $arg" >&2
            echo "Usage: $0 [--fresh] [--seed]" >&2
            exit 1
            ;;
    esac
done

cd "$(dirname "$0")/.."

if ! docker info > /dev/null 2>&1; then
    echo "ERROR: Docker doesn't seem to be running. Start Docker Desktop and try again." >&2
    exit 1
fi

if [ ! -f .env ]; then
    echo "ERROR: .env not found. Copy .env.example to .env first." >&2
    exit 1
fi

echo "==> Starting postgres and redis..."
docker compose "${COMPOSE_ARGS[@]}" up -d postgres redis

echo "==> Waiting for postgres to become healthy..."
attempts=0
max_attempts=30
until [ "$(docker compose "${COMPOSE_ARGS[@]}" ps -q postgres | xargs -r docker inspect -f '{{.State.Health.Status}}' 2>/dev/null)" = "healthy" ]; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge "$max_attempts" ]; then
        echo "ERROR: postgres did not become healthy in time. Check: docker compose -f docker-compose.yml -f docker-compose.dev.yml logs postgres" >&2
        exit 1
    fi
    sleep 2
done
echo "    postgres is healthy."

echo "==> Starting the coolify app container..."
docker compose "${COMPOSE_ARGS[@]}" up -d coolify

echo "==> Waiting for the coolify container to accept commands..."
attempts=0
max_attempts=30
until docker compose "${COMPOSE_ARGS[@]}" exec -T coolify true > /dev/null 2>&1; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge "$max_attempts" ]; then
        echo "ERROR: coolify container did not become ready in time. Check: docker compose -f docker-compose.yml -f docker-compose.dev.yml logs coolify" >&2
        exit 1
    fi
    sleep 2
done

ARTISAN_CMD="migrate"
if [ "$FRESH" = true ]; then
    ARTISAN_CMD="migrate:fresh"
fi
if [ "$SEED" = true ]; then
    ARTISAN_CMD="$ARTISAN_CMD --seed"
fi

echo "==> Running: php artisan $ARTISAN_CMD"
docker compose "${COMPOSE_ARGS[@]}" exec -T -u www-data coolify php artisan $ARTISAN_CMD --force

echo "==> Done. Dev database is ready (postgres on localhost:\${FORWARD_DB_PORT:-5432}, app on localhost:\${APP_PORT:-8000})."
