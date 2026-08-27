# Commands Reference

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: August 27, 2026**

Every command you need to develop, test, and verify this repo, grouped by what you're trying to do. This repo runs entirely inside Docker containers (via `spin`/Docker Compose) — there is no local PHP/Node install expected. Commands that must run inside a container are prefixed with `docker exec <container>`.

Every command here is **Linux-native bash** — the dev environment is Ubuntu (via WSL2 on a Windows host), and each command runs unchanged on any native Linux machine.

**Windows dev machines: the repo now lives inside the WSL2 filesystem, not under `C:\Users\...`.** See "WSL2 migration" below before assuming a Windows-path command from an older session still applies.

Compose files: `docker-compose.dev.yml` is a Compose **override** — it only adds dev-specific bits (build context, ports, volumes) on top of the base `docker-compose.yml` (which defines the actual images for `redis`/`postgres`/`soketi`). Always pass both: `docker compose -f docker-compose.yml -f docker-compose.dev.yml <command>` (or use `spin`, which does this for you). Running `-f docker-compose.dev.yml` alone fails with `service "redis" has neither an image nor a build context specified` — confirmed 2026-07-12. `docker-compose.windows.yml` is unrelated: a separate, standalone production-style config using prebuilt `ghcr.io/coollabsio/coolify` images (doesn't build from local source), not part of the dev workflow.

Container names (from `docker-compose.dev.yml`, confirmed via `docker ps`):

| Container | Role |
| --- | --- |
| `coolify` | Laravel app (PHP-FPM + web server) — serves the Inertia/React app plus a handful of plain Blade guest/auth/error pages |
| `coolify-vite` | Node/Vite dev server (hot module reload for JS/CSS/JSX) |
| `coolify-db` | PostgreSQL — the app's own database |
| `coolify-redis` | Redis (cache, queues, broadcasting) |
| `coolify-realtime` | Soketi (WebSocket server for Echo/broadcast events) |
| `coolify-testing-host` | A stand-in "remote server" for SSH-touching deploy/backup/terminal code paths. **Not actually Docker-in-Docker** — it mounts the *host's own* `/var/run/docker.sock` (Docker-**outside**-of-Docker), so any `docker` command Coolify runs against it executes on the real host daemon, seeing and able to affect this repo's own dev-stack containers. **Confirmed 2026-07-25**: creating a second `Server` row pointed at this container (even via its own container IP rather than its hostname, to dodge the IP-uniqueness guard) and validating it deployed a real `coolify-proxy` onto the shared host — a fixed, non-per-server container name — which then showed as "owned" by *both* Server rows simultaneously. Deleting the throwaway Server row did not tear that container down; it had to be stopped via the *other* (real) server's own Proxy tab. Do not create a second `Server` entry pointed at this container without expecting exactly this collision — one throwaway "server" here is the supported/tested pattern, not several. |
| `coolify-https-proxy` | **Opt-in, not started by `spin up`/the two default compose files** — added 2026-07-30 as a friendly local dev URL, `https://coolify-full.localhost:8443`. `*.localhost` resolves to loopback natively in browsers and Windows (no hosts-file edit needed); port 8443 was chosen because `coolify-proxy` (the real Traefik proxy this repo uses to test Coolify's own routing features, not a spare port) already owns 80 and 8080. Also terminates TLS on port 6443 for Soketi's websocket connection (real-time UI updates) — `coolify-realtime`'s real port, 6001, only speaks plain `ws://`, which browsers refuse to connect to from an HTTPS page (mixed content, same rule as an HTTP `<script>`); `getRealtime()`/`HandleInertiaRequests` switch to 6443 + `forceTLS: true` automatically when the request is secure. Cert/key are gitignored — never committed (a private key has no place in a public repo, and it wouldn't even help another clone, since browser trust comes from a locally-installed CA, not the file itself). Two ways to generate them locally:<br><br>**Trusted, no browser warnings (recommended)** — install [mkcert](https://github.com/FiloSottile/mkcert) and let it manage its own local CA:<br>`winget install FiloSottile.mkcert` (or `choco install mkcert`), then in an **Administrator** PowerShell: `mkcert -install`, then `mkcert coolify-full.localhost localhost <your VITE_HOST value, e.g. a LAN IP>` — copy the two generated files into `docker/https-proxy/certs/` as `dev.crt`/`dev.key`.<br><br>**Quick, no extra software** — `./docker/https-proxy/generate-cert.sh` (plain `openssl`, self-signed; reads `VITE_HOST` from `.env` and adds it as a SAN automatically). Browsers show a one-time "connection not private" warning per origin (main page, Vite assets, websocket port) since it's not from a trusted CA — click through once, remembered after that.<br><br>Either way, then: `docker compose -f docker-compose.yml -f docker-compose.dev.yml -f docker-compose.https.yml up -d https-proxy`. **Trade-off worth knowing**: once the cert exists, Vite serves *all* its assets over HTTPS for every access path, including plain `http://localhost:8000` — with the openssl path that means an extra one-time cert-warning click-through on the default workflow too; with mkcert there's no warning at all since the CA is genuinely trusted. See `docker-compose.https.yml`, `vite.config.js`, and `docker/https-proxy/conf.d/default.conf`. **Known failure mode, fixed 2026-08-15**: after a Docker Desktop/WSL2 restart, this container could fail to start at all (`docker ps -a` shows `Exited (127)`, logs show `... not a directory: Are you trying to mount a directory onto a file?`) — the same bind-mount race `coolify-autoheal` exists to paper over for the `coolify` container, but hitting `https-proxy`'s config mount instead, and with no autoheal coverage (autoheal only restarts *unhealthy* running containers, not ones that failed to start). Root cause was mounting a single file (`nginx.conf:/etc/nginx/conf.d/default.conf`) — fixed by mounting the whole `conf.d/` directory instead, which Docker Desktop handles far more reliably across restarts. If `https://coolify-full.localhost:8443` ever refuses to connect again, first check `docker ps -a --filter name=coolify-https-proxy` — an `Exited` status means `docker compose -f docker-compose.yml -f docker-compose.dev.yml -f docker-compose.https.yml up -d https-proxy` will recreate it. |
| `coolify-mail` | Mailpit — catches every outgoing email in dev instead of sending it for real; UI at `http://localhost:8025` |
| `coolify-minio` | MinIO — an S3-compatible object store standing in for a real S3 provider, so backup-to-S3 code paths can be tested against a real (if local) S3 API; console at `http://localhost:9001` |
| `coolify-minio-init` | One-shot init job — waits for MinIO to come up, then creates its default bucket. Runs once per `spin up` and exits (`restart: no`); an `Exited (0)` status for this one specifically is success, not a crash |
| `coolify-autoheal` | Watches containers carrying an `autoheal=true` label (only `coolify` itself) and restarts them if their healthcheck fails — specifically for the Docker Desktop/WSL2 post-reboot bind-mount race, not general-purpose crash recovery |
| `coolify-stray-pruner` | Added 2026-07-25: periodically removes stopped containers with **neither** a `com.docker.compose.project` label **nor** a `coolify.managed` label — leftovers from a manual, ad-hoc `docker run` on the host match neither. Checking only the first label was tried initially and genuinely deleted the real `coolify-sentinel` container within hours (it carries `coolify.managed` but no compose-project label, unlike every other Coolify-managed container) — see `docker-compose.dev.yml` for the full incident and why both checks are now required. |

**Not started by these compose files at all — created and torn down by Coolify's own backend at runtime, the same way they would be on a real production install:**

| Container | Role |
| --- | --- |
| `coolify-proxy` | Traefik — the reverse proxy Coolify manages for every deployed application/service's domain routing. Carries `coolify.managed=true` and its own Compose project label (`coolify-proxy`) |
| `coolify-sentinel` | Coolify's own lightweight metrics/monitoring agent. Carries `coolify.managed=true` but **no Compose project label at all** (created via a direct Docker API call, not `docker compose`) — the one exception worth knowing, since anything filtering "real" containers by compose-project label alone will miss this one. Recreate it via the real app flow if it's ever gone: `/server/{uuid}/sentinel` page → "Sync" button (`StartSentinel::run()`), not by hand |
| *(one per deployed resource)* | Every application, database, and service you actually deploy through the UI gets its own container (and often its own Compose project), named after that resource's UUID, carrying `coolify.managed=true` — this is Coolify managing the very thing it's a PaaS for, not part of the dev environment itself |

Because none of these three carry this repo's own Compose project label, Docker Desktop will always show them as separate, "ungrouped" entries alongside the `coolify-full` stack above — that's expected on any Coolify install, dev or real, not a sign of anything broken.

**The UUID container names aren't cosmetic — don't rename them.** Every action Coolify takes against a deployed resource (start/stop/restart, running a command inside it, streaming its logs, taking a backup) recomputes the target container's name from the resource's own `uuid` column fresh, every single time — e.g. `app/Actions/Database/StartPostgresql.php:36`: `$container_name = $this->database->uuid;`. Nothing caches or stores the container's name separately; the UUID *is* the name, by contract. Renaming the Docker container directly (`docker rename`, or via Docker Desktop) desyncs that contract instantly — the next start/stop/backup/terminal action looks for a container that, as far as it's concerned, no longer exists. If a resource's container needs a human-friendly label for browsing, that's what its `name` field in the Coolify UI is for; the Docker-level container name is internal plumbing, not user-facing.

## Starting/stopping the whole dev environment

This is one Laravel backend running in one `coolify` container — `coolify-vite` compiles/serves the React/Inertia JS bundle (and the near-empty `app.js` entrypoint the few remaining plain-Blade pages use) through the same Vite pipeline.

```bash
spin up                          # start everything (or: docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d)
spin down                        # stop everything
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps        # check container status
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f coolify        # tail app logs
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f coolify-vite   # tail Vite dev server logs
```

**After a Windows/WSL2 reboot**, run `./scripts/dev-up.sh` once you're back at the machine. It detects and fixes the Docker Desktop/WSL2 bind-mount race that can leave `coolify` (and occasionally `soketi`/`autoheal`/`testing-host`, plus `https-proxy` if opted in — it checks for `docker/https-proxy/certs/dev.crt` to decide) unhealthy or `Exited (127)` right after boot. A container-native auto-fix (`mount-doctor`) was tried but had to be removed — it needed `docker.sock`, which is itself caught in the same race, so nothing in-container can reliably self-heal this; a host-side script is the one thing immune to it. See [`DEVELOPING_IN_CONTAINERS_WINDOWS.md`](../DEVELOPING_IN_CONTAINERS_WINDOWS.md)'s "After a Windows reboot, `coolify` comes up unhealthy with `artisan` missing" section for the full story. **Fixed 2026-08-15**: `https-proxy` had silently been excluded from this script entirely (not started, checked, or recovered) since it was added 2026-07-30 — the actual reason `https://coolify-full.localhost:8443` kept going down after reboots without the script ever flagging it.

Once the dev stack itself is healthy, the script also brings back the seeded dev database fixtures (E-Commerce Platform / Internal Tools projects). Coolify-managed resource containers don't come back on their own after a reboot — they run with `restart: unless-stopped`, but Docker Desktop's own restart sequence stops them gracefully first, so `unless-stopped` correctly does *not* auto-restart them. `dev-up.sh` dispatches the same `StartDatabase` action the UI's Start button triggers, scoped only to those two known persistent projects (never throwaway smoke-test resources, which may be deliberately stopped). Live-verified 2026-07-30: stopped the real seeded Postgres container, ran the script's logic, confirmed it correctly detected the stopped state, dispatched Start, and the container came back `running` then `healthy` within 15 seconds.

App: `http://localhost:8000` · Vite dev server: `http://localhost:5173` · Mailpit UI: `http://localhost:8025` · MinIO console: `http://localhost:9001`

## Frontend (Vite / React assets)

The `coolify-vite` container already runs `yarn dev` automatically on `spin up` (see its `command:` in `docker-compose.dev.yml`) — you generally don't need to start it manually. Use these when you need to run Yarn commands directly (e.g. installing a new package, or a one-off production build to verify compilation):

```bash
docker exec coolify-vite yarn install              # install/sync node_modules
docker exec coolify-vite yarn dev                  # start Vite dev server manually (already running by default)
docker exec coolify-vite yarn build                # production build — confirms all JS/JSX compiles cleanly
docker exec coolify-vite yarn add <package>         # add a runtime dependency
docker exec coolify-vite yarn add -D <package>      # add a dev dependency
```

If a frontend change isn't showing up in the browser, first check `coolify-vite` is actually running (`docker compose -f docker-compose.yml -f docker-compose.dev.yml ps`) before assuming a build is needed — the dev server hot-reloads automatically.

### WSL2 migration — RESOLVED: `docker exec coolify-vite yarn build` was extremely slow on Windows, fixed 2026-07-12 by moving the repo into WSL2

Originally confirmed 2026-07-11: `docker exec coolify-vite yarn build` ran for **over 3 hours** without finishing. Root-caused via `/proc/<pid>/stat`/`wchan`: the build process sat in uninterruptible sleep (`D` state) blocked on `p9_client_rpc` — the 9P protocol Docker Desktop's WSL2 backend uses to bridge file access between the Linux VM and the Windows NTFS host filesystem. The repo lived at `C:\Users\...` and every container bind-mounted it (`.:/var/www/html`), so every file the build touched crossed that boundary, with Windows Defender re-scanning each crossing on top. The same build run natively on the Windows host (bypassing the bridge entirely) took under 10 seconds — proving the bottleneck was the bridge itself, not the build.

**Fix applied 2026-07-12**: installed a real WSL2 Linux distro (`wsl --install -d Ubuntu` — Docker Desktop's own internal `docker-desktop` distro isn't safe to store a project in, since Docker Desktop can wipe/reset it on updates) and moved the repo into that distro's native filesystem, at `/root/projects/coolify-full` (formerly `C:\Users\Terre\source\repos\coolify-full`). Both `coolify` and `coolify-vite`'s bind mounts now read/write WSL2-native ext4, not NTFS-over-9P, so there's no bridge left for any container operation to cross — not just builds. Result: `docker exec coolify-vite yarn build` (the exact same command that used to take 3+ hours) now completes in **~2 seconds**. The full Pest suite (678 tests) dropped from ~150-170s to ~31s over the same move.

**Migration steps, for reference (or if this needs redoing on another machine)**:

1. Install Ubuntu: `wsl --install -d Ubuntu`, then `wsl -d Ubuntu` once to complete the interactive first-run username/password setup (only needed for an interactive login shell — non-interactive `wsl -d Ubuntu -- <command>` invocations run as root regardless and don't need it). `wsl --set-default Ubuntu` afterward is optional (just makes plain `wsl` default to it instead of Docker Desktop's internal distro).
2. Enable Docker Desktop's WSL integration for the new distro: **Settings → Resources → WSL Integration → toggle on** for the distro, then Apply & Restart. Without this, `docker` isn't on `PATH` inside the distro at all (`The command 'docker' could not be found in this WSL 2 distro`).
3. Copy the working tree across (not a fresh `git clone`, to preserve any uncommitted work): `rsync -a --exclude=/node_modules --exclude=/vendor --exclude=docker/coolify-realtime/node_modules --exclude=public/build --exclude=public/hot --exclude=.phpunit.cache /mnt/c/Users/.../coolify-full/ /root/projects/coolify-full/`. **The excludes must be anchored with a leading `/`** — a bare `--exclude=vendor` matches "vendor" at *any* depth, not just the top-level Composer directory, and will also silently drop `public/vendor/` (Horizon/Telescope published assets) and `resources/views/vendor/` (Laravel's default mail templates). Confirmed this exact mistake once; recovered by re-`rsync`-ing just those two subpaths, then verified `git status --short` in the copy had zero unexpected `D` (deleted) entries before proceeding — check for that before trusting any similar copy.
4. `node_modules` and `vendor` don't need pre-installing on the host: `coolify-vite`'s container command already runs `yarn install` on startup, and the `coolify` container's own init service runs `composer install`, `php artisan migrate`, and `php artisan dev --init` (APP_KEY generation, storage symlink, first-boot seeding) automatically against the bind mount.
5. Bring the old (Windows-path) stack down first — `docker compose -f docker-compose.yml -f docker-compose.dev.yml down` — before bringing the new one up from the WSL2 path, since both would otherwise fight over the same container/network names. Named volumes (`dev_postgres_data`, `dev_redis_data`, etc.) aren't touched by `down` (only `down -v` removes them), so the dev database survives the move as long as the Compose project name matches (same directory basename in both locations keeps Compose's default project-name derivation consistent).
6. If rebuilding the `coolify` image from scratch (not reusing a cached one) fails with `nginx-X.Y.Z-rN: breaks: world[nginx=A.B.C-rN@nginx]` from `docker/development/Dockerfile`'s Nginx install step — the base image (`serversideup/php:8.4-fpm-nginx-alpine`) updates its Alpine version independently of this repo, and the Dockerfile's `NGINX_VERSION` ARG pins an exact nginx.org package version that may no longer exist for whatever Alpine version the base image now ships. Check what's actually available (`curl -s https://nginx.org/packages/mainline/alpine/v<version>/main/x86_64/` and grep for `nginx-`) and bump the ARG to match.

A cached `coolify:dev`/`coolify-vite:dev`/`coolify-realtime:dev` image tag from a prior successful build persists in Docker Desktop's image store independently of which WSL distro or host path last built it (image storage isn't tied to a specific bind-mounted directory) — if a rebuild fails partway through, `docker compose up -d` (without `--build`) can still bring the stack up on the last-good cached image while the build issue gets fixed separately.

## Backend (Laravel / Artisan)

```bash
docker exec coolify php artisan list                          # list all artisan commands
docker exec coolify php artisan route:list                    # list all routes
docker exec coolify php artisan route:list --name=<name>      # filter routes by name
docker exec coolify php artisan route:list --path=<path>      # filter routes by path
docker exec coolify php artisan config:show <key>              # inspect a config value, e.g. app.name
docker exec coolify php artisan tinker --execute '<code>'      # run PHP in app context (single quotes; double quotes for PHP strings inside)
docker exec coolify php artisan migrate                        # run pending migrations
docker exec coolify php artisan migrate:fresh --seed           # drop all tables, re-migrate, reseed (destructive — dev DB only)
docker exec coolify php artisan make:controller <Name>Controller --no-interaction
docker exec coolify php artisan make:model <Name> --no-interaction
docker exec coolify php artisan make:test --pest <Name>Test --no-interaction
docker exec coolify php artisan make:test --pest --unit <Name>Test --no-interaction
docker exec coolify php artisan vendor:publish --provider='<ServiceProvider>'
```

## Tests (Pest 4)

`scripts/run-tests.sh` wraps every form below with one guard: it checks for and kills any
already-running `artisan test`/Pest process in the container before starting a new one. A test
run left running in the background (a closed terminal, an interrupted session) keeps going as an
orphan; starting a second run on top of it makes both crawl, competing for the same SQLite test
DB, and can look like a hang or a real regression when it's neither. Prefer it over calling
`docker exec` directly for anything longer than a single test.

```bash
scripts/run-tests.sh --compact                                    # full suite
scripts/run-tests.sh --compact --filter=<testName>                # single test by name
scripts/run-tests.sh --compact tests/Feature/SomeTest.php         # single file
scripts/run-tests.sh --compact --filter="<ClassName>"             # single test file/class by filter
scripts/run-tests.sh --compact --order-by=random                  # catches order-dependent failures (config() leakage etc.)
docker exec coolify sh -lc "cd /var/www/html && vendor/bin/pest --testdox-html storage/test-report.html"   # full suite with an HTML report
```

For a run long enough that whatever started it (a terminal, an assistant session) might not
stay attached the whole way through, use `--detached` — it launches via `docker compose exec -d`
so the run lives entirely inside the container, and `scripts/test-status.sh` checks on it from
any shell, anytime, without needing to have been the one that started it:

```bash
scripts/run-tests.sh --detached --compact
scripts/test-status.sh
```

Every form above runs with `--parallel` by default (paratest is already vendored). Safe here
because every feature test runs on `RefreshDatabase` against SQLite `:memory:`, which is private
to each OS process paratest spawns — no shared DB to race on. Pass `--no-parallel` to fall back
to sequential if that assumption is ever broken by a future test. The `audit` log channel is
also forced to a no-op `NullHandler` in the testing environment (`LOG_AUDIT_DRIVER=monolog` in
`phpunit.xml`, see `config/logging.php`) — several API controllers write to it on every mutating
request, and under `--parallel` all workers were serializing on writes to that one shared file.

## Code quality

```bash
docker exec coolify vendor/bin/pint --dirty --format agent      # format only changed files (always run before finalizing PHP changes)
docker exec coolify vendor/bin/pint --format agent               # format the whole codebase
docker exec coolify composer phpstan                             # static analysis (uses phpstan-baseline.neon for known nits)
docker exec coolify composer psalm                                # taint analysis (SQL injection, XSS, command injection dataflow — not general type-checking, that's PHPStan's job)
```

While actively editing, run `pint --format agent` (or `--dirty --format agent` for just the changed files) — it fixes issues directly rather than just reporting them. But `--dirty`'s diff-based file selection has been observed to miss real style issues the full check catches (a `fully_qualified_strict_types` violation once passed `--dirty` locally and only surfaced when the full `pint --test` ran in CI) — before pushing, run the full `vendor/bin/pint --test` (no `--dirty`, matching the "CI parity" section below) as a final check, not just the scoped one.

## Composer

```bash
docker exec coolify composer install --no-interaction --prefer-dist --optimize-autoloader
docker exec coolify composer require <package>
docker exec coolify composer require --dev <package>
docker exec coolify composer validate
docker exec coolify composer dump-autoload
```

## Database access

Prefer the Laravel Boost MCP tools (`database-query`, `database-schema`) over raw SQL when working from an agent session. Direct `psql` access if needed:

```bash
docker exec -it coolify-db psql -U coolify -d coolify
```

## CI parity (what GitHub Actions actually runs)

From `.github/workflows/quality.yml` — reproduce these exactly when debugging a CI-only failure:

```bash
cp .env.testing .env
composer install --no-interaction --prefer-dist --optimize-autoloader
composer phpstan                          # separate CI job: "phpstan"
vendor/bin/pint --test                    # separate CI job: "pint" (format check, not --dirty — the whole repo)
composer psalm                            # separate CI job: "psalm" (taint analysis)
yarn install --frozen-lockfile
yarn build
php artisan test --compact                # separate CI job: "tests" — note it builds frontend assets first, unlike local dev
yarn test                                 # separate CI job: "vitest" — React component tests, no PHP/build needed
yarn format:check                         # separate CI job: "prettier" — no PHP/build needed
```

`yarn lint` (ESLint) is **not** in CI yet — it was deliberately held back until the 20 `react-hooks/set-state-in-effect` findings that used to fail it were resolved individually (per-effect review, not a mechanical fix; see [issue #33](https://github.com/Terrence721/coolify-full/issues/33), closed 2026-07-31 with the baseline at 0). That's now done, so adding the CI gate is unblocked — see [`todo.md`](../todo.md)'s still-open items for the tracking status.

`.github/workflows/codeql.yml` runs separately (its own workflow, not part of `quality.yml`) and scans `resources/js/` only — CodeQL has no PHP support, so there's nothing to reproduce locally for the PHP side beyond the `psalm` job above. See [`todo.md`](../todo.md)'s "GitHub repo-level security features" entry for why both tools exist.

The most common source of CI-only failures in this repo has been environment divergence from the Windows/Docker dev setup (case-insensitive filesystem, always-on Redis, a running Vite dev server masking missing-build errors) — see [`docs/livewire-to-react-migration.md`](livewire-to-react-migration.md) for specific incidents. When in doubt, run the block above verbatim inside the `coolify` container rather than the everyday shortcuts further up this file.
