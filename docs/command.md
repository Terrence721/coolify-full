# Commands Reference

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 16, 2026**

Every command you need to develop, test, and verify this repo, grouped by what you're trying to do. This repo runs entirely inside Docker containers (via `spin`/Docker Compose) — there is no local PHP/Node install expected. Commands that must run inside a container are prefixed with `docker exec <container>`.

Every command here is **Linux-native bash** — the dev environment is Ubuntu (via WSL2 on a Windows host), and each command runs unchanged on any native Linux machine.

**Windows dev machines: the repo now lives inside the WSL2 filesystem, not under `C:\Users\...`.** See "WSL2 migration" below before assuming a Windows-path command from an older session still applies.

Compose files: `docker-compose.dev.yml` is a Compose **override** — it only adds dev-specific bits (build context, ports, volumes) on top of the base `docker-compose.yml` (which defines the actual images for `redis`/`postgres`/`soketi`). Always pass both: `docker compose -f docker-compose.yml -f docker-compose.dev.yml <command>` (or use `spin`, which does this for you). Running `-f docker-compose.dev.yml` alone fails with `service "redis" has neither an image nor a build context specified` — confirmed 2026-07-12. `docker-compose.windows.yml` is unrelated: a separate, standalone production-style config using prebuilt `ghcr.io/coollabsio/coolify` images (doesn't build from local source), not part of the dev workflow.

Container names (from `docker-compose.dev.yml`, confirmed via `docker ps`):

| Container | Role |
| --- | --- |
| `coolify` | Laravel app (PHP-FPM + web server) — serves the Inertia/React app plus a handful of plain Blade guest/auth/error pages |
| `coolify-vite` | Node/Vite dev server (hot module reload for JS/CSS/JSX) |
| `coolify-db` | PostgreSQL |
| `coolify-redis` | Redis (cache, queues, broadcasting) |
| `coolify-realtime` | Soketi (WebSocket server for Echo/broadcast events) |

## Starting/stopping the whole dev environment

This is one Laravel backend running in one `coolify` container — `coolify-vite` compiles/serves the React/Inertia JS bundle (and the near-empty `app.js` entrypoint the few remaining plain-Blade pages use) through the same Vite pipeline.

```bash
spin up                          # start everything (or: docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d)
spin down                        # stop everything
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps        # check container status
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f coolify        # tail app logs
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f coolify-vite   # tail Vite dev server logs
```

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

### RESOLVED: `docker exec coolify-vite yarn build` was extremely slow on Windows — fixed 2026-07-12 by moving the repo into WSL2

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

```bash
docker exec coolify php artisan test --compact                                    # full suite
docker exec coolify php artisan test --compact --filter=<testName>                # single test by name
docker exec coolify php artisan test --compact tests/Feature/SomeTest.php         # single file
docker exec coolify php artisan test --compact --filter="<ClassName>"             # single test file/class by filter
docker exec coolify php artisan test --compact --order-by=random                  # catches order-dependent failures (config() leakage etc.)
docker exec coolify sh -lc "cd /var/www/html && vendor/bin/pest --testdox-html storage/test-report.html"   # full suite with an HTML report
```

## Code quality

```bash
docker exec coolify vendor/bin/pint --dirty --format agent      # format only changed files (always run before finalizing PHP changes)
docker exec coolify vendor/bin/pint --format agent               # format the whole codebase
docker exec coolify composer phpstan                             # static analysis (uses phpstan-baseline.neon for known nits)
```

Never run `pint --test` — just run `pint --format agent`, it fixes issues directly.

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
yarn install --frozen-lockfile
yarn build
php artisan test --compact                # separate CI job: "tests" — note it builds frontend assets first, unlike local dev
```

The most common source of CI-only failures in this repo has been environment divergence from the Windows/Docker dev setup (case-insensitive filesystem, always-on Redis, a running Vite dev server masking missing-build errors) — see `docs/livewire-to-react-migration.md` for specific incidents. When in doubt, run the block above verbatim inside the `coolify` container rather than the everyday shortcuts further up this file.
