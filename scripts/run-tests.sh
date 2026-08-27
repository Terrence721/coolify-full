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
# Foreground usage (blocks until done, same as `php artisan test`):
#   scripts/run-tests.sh --compact
#   scripts/run-tests.sh --compact tests/v4/Feature/Api/
#   scripts/run-tests.sh --compact --filter=SomeTest
#
# Detached usage, for a run long enough that a client/session/terminal might not stay
# attached the whole time (the full suite, ~130s+): the run's tracking lived only in the
# calling shell/session before, which could lose track of it mid-run (e.g. across a
# terminal restart) with no way to tell "still running" from "actually stuck" apart from
# guessing. --detached instead launches via `docker compose exec -d`, so the test process
# lives entirely inside the container, independent of whatever started it - check on it
# anytime after with scripts/test-status.sh, from any shell, in this session or a new one:
#   scripts/run-tests.sh --detached --compact
#   scripts/test-status.sh
#
# Runs with `--parallel` by default (paratest is already vendored via a transitive
# dependency). Safe here specifically because: every feature test already runs on
# `RefreshDatabase` against sqlite `:memory:`, which is private to each OS process paratest
# spawns (no shared DB to race on); CACHE_DRIVER/SESSION_DRIVER are both "array", so
# in-process only; and no test file writes to a real shared path outside Storage::fake()
# except tests/Unit/Services/ConfigurationGeneratorTest.php, which is safe because Laravel's
# parallel runner splits work by whole file, so that file's own writes never run
# concurrently with themselves. Pass --no-parallel to fall back to sequential if a future
# test file breaks one of those assumptions.

set -euo pipefail

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.dev.yml)
LOG_PATH="/tmp/run-tests.log"

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

ARGS=("$@")
PARALLEL=(--parallel)
FILTERED_ARGS=()
for arg in "${ARGS[@]}"; do
    if [ "$arg" = "--no-parallel" ]; then
        PARALLEL=()
    else
        FILTERED_ARGS+=("$arg")
    fi
done

if [ "${FILTERED_ARGS[0]:-}" = "--detached" ]; then
    FILTERED_ARGS=("${FILTERED_ARGS[@]:1}")
    "${COMPOSE[@]}" exec -T coolify rm -f "$LOG_PATH"
    # Docker's own -d detaches the exec session immediately; the command keeps running
    # inside the container regardless of what happens to the client that started it -
    # no shell backgrounding tricks, no dependency on this session staying alive.
    "${COMPOSE[@]}" exec -d coolify sh -c "php artisan test ${PARALLEL[*]:-} ${FILTERED_ARGS[*]} > $LOG_PATH 2>&1; echo \"###DONE exit=\$?###\" >> $LOG_PATH"
    echo "Started detached, logging to $LOG_PATH inside the container."
    echo "Check status anytime with: scripts/test-status.sh"
else
    "${COMPOSE[@]}" exec -T coolify php artisan test "${PARALLEL[@]}" "${FILTERED_ARGS[@]}"
fi
