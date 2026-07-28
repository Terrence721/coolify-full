#!/bin/sh
set -e

# Starts this container's own, fully isolated Docker daemon in the background, then hands
# off to sshd in the foreground. Unlike docker/testing-host/Dockerfile (which only ships the
# Docker CLI talking to the *host's* bind-mounted /var/run/docker.sock), this image runs a real
# dockerd of its own - that isolation is the entire point, see docs/command.md.
dockerd --host=unix:///var/run/docker.sock &

timeout=30
until docker version >/dev/null 2>&1 || [ "$timeout" -le 0 ]; do
    sleep 1
    timeout=$((timeout - 1))
done

if ! docker version >/dev/null 2>&1; then
    echo "dockerd did not become ready in time" >&2
    exit 1
fi

exec /usr/sbin/sshd -D -o ListenAddress=0.0.0.0 -o Port=22
