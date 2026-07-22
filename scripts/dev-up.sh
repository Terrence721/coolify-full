#!/usr/bin/env bash
#
# Starts the full dev stack and works around a real, confirmed Docker Desktop/WSL2
# bind-mount race on reboot. It shows up in two different shapes depending on mount type:
#
#   - DIRECTORY bind mounts (coolify's `.:/var/www/html`) attach empty - the container
#     starts fine but `artisan` and everything else is missing until it's restarted.
#   - FILE bind mounts (docker.sock for autoheal/testing-host, soketi's individual
#     terminal-server.js/terminal-utils.js) fail harder: the container's OCI creation
#     itself errors out ("mounting a directory onto a file"), so the container never
#     starts at all and sits `Exited (127)`.
#
# A container-native fix (e.g. a sidecar with restart:always) was tried and confirmed
# NOT to work (2026-07-22, verified via a real reboot): a sidecar needs docker.sock to
# do anything, and docker.sock is itself a file-mount victim of the exact same race -
# so the fixer container gets caught in the same failure it exists to repair. Nothing
# running inside a container can recover from a failure that happens before that
# container can be created. Only host-side code (this script) is immune, since it
# talks to the already-running dockerd directly rather than needing its own mount.
#
# In real testing, the file-mount race took up to ~10 minutes post-boot to clear on its
# own - this script retries with real persistence rather than assuming a few seconds is
# enough.
#
# A third variant confirmed 2026-07-22 (Docker Desktop upgrade, not just a reboot):
# coolify-testing-host can come up with container status "running" while its
# docker.sock mount is still a stale/broken socket internally - `docker version` inside
# it returns a Client block but a null Server block ("Cannot connect to the Docker
# daemon"). This silently breaks any server-connectivity/Docker-availability check that
# uses this host as the target (e.g. ScheduledJobManager's docker-cleanup skip check),
# without the container ever showing as unhealthy or exited. A plain restart fixes it,
# but "container status is running" alone isn't sufficient evidence for this one -
# functionally verified below.
#
# Usage: ./scripts/dev-up.sh   (run this after logging back in following any reboot)

set -euo pipefail
cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.yml -f docker-compose.dev.yml"
MAX_ATTEMPTS=18
SLEEP_SECONDS=10

echo "==> Starting the dev stack..."
$COMPOSE up -d

echo "==> Waiting for Docker to settle before checking mounts..."
sleep "$SLEEP_SECONDS"

mount_is_empty() {
    # A real, populated mount always has artisan at the repo root. An empty mount
    # (the WSL2 race) shows just the storage/ volume Docker created on its own.
    ! docker exec coolify test -f /var/www/html/artisan 2>/dev/null
}

attempt=1
while mount_is_empty; do
    if [ "$attempt" -gt "$MAX_ATTEMPTS" ]; then
        echo "==> ERROR: coolify's bind mount is still empty after $MAX_ATTEMPTS attempts."
        echo "    This is no longer the known WSL2-readiness race - something else is wrong."
        echo "    Check: docker exec coolify ls -la /var/www/html"
        exit 1
    fi

    echo "==> coolify's bind mount came up empty (attempt $attempt/$MAX_ATTEMPTS) - restarting it..."
    docker restart coolify >/dev/null
    sleep "$SLEEP_SECONDS"
    attempt=$((attempt + 1))
done

echo "==> coolify's mount is populated. Recovering any container caught in the file-mount race..."
# autoheal/testing-host (docker.sock) and soketi (individual .js file mounts) can fail
# OCI creation outright on reboot and sit Exited(127) - a plain `docker restart` from
# the host (not from inside another container) reliably clears this once Desktop's
# WSL2 bind-mount proxy has caught up, but that can take several minutes, so retry
# with the same persistence as the coolify loop above instead of trying once and
# giving up.
for service in coolify-realtime coolify-autoheal coolify-testing-host; do
    attempt=1
    while true; do
        status=$(docker inspect -f '{{.State.Status}}' "$service" 2>/dev/null || echo "missing")
        if [ "$status" = "running" ]; then
            break
        fi
        if [ "$attempt" -gt "$MAX_ATTEMPTS" ]; then
            echo "    - $service is still '$status' after $MAX_ATTEMPTS attempts - giving up on it."
            break
        fi
        echo "    - $service is '$status' (attempt $attempt/$MAX_ATTEMPTS), restarting..."
        docker restart "$service" >/dev/null 2>&1 || true
        sleep "$SLEEP_SECONDS"
        attempt=$((attempt + 1))
    done
done

echo "==> Verifying coolify-testing-host's docker.sock actually works (not just that the container is running)..."
# "running" alone doesn't prove the socket reconnected - docker version's Server block is
# null when it's stale. A restart of an already-"running" container still re-attaches the
# mount, same remedy as the exited-container case above.
attempt=1
while true; do
    if docker exec coolify-testing-host docker version --format '{{json .Server}}' 2>/dev/null | grep -qv '^null$'; then
        break
    fi
    if [ "$attempt" -gt "$MAX_ATTEMPTS" ]; then
        echo "    - coolify-testing-host's docker.sock is still not functional after $MAX_ATTEMPTS attempts - giving up on it."
        break
    fi
    echo "    - coolify-testing-host's docker.sock isn't functional yet (attempt $attempt/$MAX_ATTEMPTS), restarting..."
    docker restart coolify-testing-host >/dev/null 2>&1 || true
    sleep "$SLEEP_SECONDS"
    attempt=$((attempt + 1))
done

echo "==> Waiting for healthchecks to settle..."
sleep "$SLEEP_SECONDS"

echo ""
echo "==> Final status:"
$COMPOSE ps -a

unhealthy=$($COMPOSE ps -a --format '{{.Names}} {{.Status}}' | grep -Ei "unhealthy|exited \(" | grep -v "coolify-minio-init" || true)
if [ -n "$unhealthy" ]; then
    echo ""
    echo "==> WARNING: still not clean:"
    echo "$unhealthy"
    exit 1
fi

echo ""
echo "==> All containers healthy. (coolify-minio-init exiting with code 0 is expected - it's a one-shot bucket-setup job.)"
