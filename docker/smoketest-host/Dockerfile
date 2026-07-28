# A genuinely isolated "remote server" for the 4 smoke-test checklist items that need real
# destructive Docker/OS actions (container restart/stop, `docker system prune`, an OS package
# upgrade that may bounce Docker) tested safely - unlike docker/testing-host/Dockerfile, which
# bind-mounts the *host's own* /var/run/docker.sock (Docker-outside-of-Docker), this image runs
# its own real dockerd, installed the normal OS-package-manager way so an `apt upgrade` here has
# genuine Docker packages to touch, matching production reality. Opt-in only - see
# docker-compose.smoketest.yml and docs/command.md.

FROM debian:12-slim

USER root
WORKDIR /root

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg openssh-server sudo git jq iptables \
    && rm -rf /var/lib/apt/lists/*

# Install Docker Engine + Compose v2 from Docker's own apt repository (not a static binary
# download) - this is what makes both (a) a real, isolated dockerd and (b) apt-manageable
# docker-ce/containerd.io packages for the security/patches smoke-test item to genuinely exercise.
RUN install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && chmod a+r /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

# Setup sshd - a distinct keypair from testing-host's, registered separately in
# database/seeders/SmoketestHostSeeder.php (not the default seeder chain - opt-in).
RUN ssh-keygen -A \
    && mkdir -p /run/sshd ~/.ssh \
    && echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFWv1FWTl/sH+U5kK3lIfUvvB6BJOs79GBcmJjWLpNzH coolify@smoketest-host" >> ~/.ssh/authorized_keys \
    && chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys

COPY docker/smoketest-host/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 22
CMD ["/entrypoint.sh"]
