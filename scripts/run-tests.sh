#!/usr/bin/env bash
#
# Runs the Pest suite inside the app container, guarding against a real failure mode:
# a long `php artisan test` run left backgrounded (e.g. a terminal/session that got
# interrupted or torn down) keeps running orphaned inside the container even after
# whatever started it is gone. Starting a fresh run on top of that orphan puts two
# full suites competing for the same SQLite test DB and CPU at once - both slow to a
# crawl, neither finishes in anything close to its normal time (~130s for the full
# suite), and it looks like the code broke something when nothing did. This script
# clears any stale run first so that can't happen silently.
#
# Usage: same arguments as `php artisan test` itself, e.g.:
#   scripts/run-tests.sh --compact
#   scripts/run-tests.sh --compact tests/v4/Feature/Api/
#   scripts/run-tests.sh --compact --filter=SomeTest

set -euo pipefail

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.dev.yml)

if ! "${COMPOSE[@]}" exec -T coolify true >/dev/null 2>&1; then
    echo "The coolify container isn't up - start the dev stack first (scripts/dev-up.sh)." >&2
    exit 1
fi

STALE_PIDS=$("${COMPOSE[@]}" exec -T coolify sh -c "ps aux | grep -E 'artisan test|pest/bin/pest' | grep -v grep | awk '{print \$1}'" 2>/dev/null || true)

if [ -n "$STALE_PIDS" ]; then
    echo "Found a stale test run already going (PIDs: $(echo "$STALE_PIDS" | tr '\n' ' ')) - clearing it before starting a new one." >&2
    # shellcheck disable=SC2086
    "${COMPOSE[@]}" exec -T coolify kill -9 $STALE_PIDS 2>/dev/null || true
    sleep 1
fi

"${COMPOSE[@]}" exec -T coolify php artisan test "$@"
