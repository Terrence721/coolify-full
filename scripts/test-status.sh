#!/usr/bin/env bash
#
# Checks on a test run started with `scripts/run-tests.sh --detached`. Safe to run from
# any shell, at any time, in this session or a brand new one - it only reads the log file
# and checks whether the process is still alive inside the container, it never depends on
# whatever started the run still being around.

set -euo pipefail

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.dev.yml)
LOG_PATH="/tmp/run-tests.log"

if ! "${COMPOSE[@]}" exec -T coolify test -f "$LOG_PATH" 2>/dev/null; then
    echo "No detached run found ($LOG_PATH doesn't exist) - nothing was started with --detached, or the container restarted since."
    exit 1
fi

if "${COMPOSE[@]}" exec -T coolify grep -q '###DONE' "$LOG_PATH" 2>/dev/null; then
    echo "=== Finished ==="
    "${COMPOSE[@]}" exec -T coolify cat "$LOG_PATH"
else
    RUNNING=$("${COMPOSE[@]}" exec -T coolify sh -c "ps aux | grep -E 'artisan test|pest/bin/pest' | grep -v grep" 2>/dev/null || true)
    if [ -n "$RUNNING" ]; then
        echo "=== Still running ==="
    else
        echo "=== No longer running, but no ###DONE marker either - it was likely killed mid-run ==="
    fi
    echo "--- last 20 lines of $LOG_PATH ---"
    "${COMPOSE[@]}" exec -T coolify tail -n 20 "$LOG_PATH"
fi
