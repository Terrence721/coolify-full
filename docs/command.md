# Commands Reference

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 12, 2026**

Every command you need to develop, test, and verify this repo, grouped by what you're trying to do. This repo runs entirely inside Docker containers (via `spin`/Docker Compose) — there is no local PHP/Node install expected. Commands that must run inside a container are prefixed with `docker exec <container>`.

Container names (from `docker-compose.dev.yml`, confirmed via `docker ps`):

| Container | Role |
| --- | --- |
| `coolify` | Laravel app (PHP-FPM + web server) — serves both Livewire and Inertia/React pages |
| `coolify-vite` | Node/Vite dev server (hot module reload for JS/CSS/JSX) |
| `coolify-db` | PostgreSQL |
| `coolify-redis` | Redis (cache, queues, broadcasting) |
| `coolify-realtime` | Soketi (WebSocket server for Echo/broadcast events) |

## Starting/stopping the whole dev environment

This single stack runs **both** the Livewire app and the React/Inertia app — they're the same Laravel backend and the same `coolify` container. There's nothing separate to start for "the React app"; `coolify-vite` just compiles/serves the JS (Livewire's Alpine/JS assets and the React/Inertia bundle both go through the same Vite pipeline).

```bash
spin up                          # start everything (or: docker compose -f docker-compose.dev.yml up -d)
spin down                        # stop everything
docker compose -f docker-compose.dev.yml ps        # check container status
docker compose -f docker-compose.dev.yml logs -f coolify        # tail app logs
docker compose -f docker-compose.dev.yml logs -f coolify-vite   # tail Vite dev server logs
```

App: `http://localhost:8000` · Vite dev server: `http://localhost:5173` · Mailpit UI: `http://localhost:8025` · MinIO console: `http://localhost:9001`

## Frontend (Vite / React / Livewire assets)

The `coolify-vite` container already runs `yarn dev` automatically on `spin up` (see its `command:` in `docker-compose.dev.yml`) — you generally don't need to start it manually. Use these when you need to run Yarn commands directly (e.g. installing a new package, or a one-off production build to verify compilation):

```bash
docker exec coolify-vite yarn install              # install/sync node_modules
docker exec coolify-vite yarn dev                  # start Vite dev server manually (already running by default)
docker exec coolify-vite yarn build                # production build — confirms all JS/JSX compiles cleanly
docker exec coolify-vite yarn add <package>         # add a runtime dependency
docker exec coolify-vite yarn add -D <package>      # add a dev dependency
```

If a frontend change isn't showing up in the browser, first check `coolify-vite` is actually running (`docker compose -f docker-compose.dev.yml ps`) before assuming a build is needed — the dev server hot-reloads automatically.

### Known issue: `docker exec coolify-vite yarn build` can be extremely slow on Windows

Confirmed on 2026-07-11: `docker exec coolify-vite yarn build` ran for **over 3 hours** without finishing (repeated attempts, including with the `coolify-vite` dev server stopped to rule out resource contention). Root-caused via `/proc/<pid>/stat`/`wchan`: the build process sat in uninterruptible sleep (`D` state) blocked on `p9_client_rpc` — the 9P protocol Docker Desktop's WSL2 backend uses to bridge file access between the Linux VM and the Windows NTFS host filesystem. This repo's bind mount (`.:/var/www/html`, from `docker-compose.dev.yml`) means every file `vite build`/rolldown touches during module resolution crosses that boundary, and Windows Defender's real-time protection scans each cross-boundary access on top of that. A build that reads thousands of files (this app's `node_modules` + `resources/js`) pays that per-file tax thousands of times over. Isolating the build in a fresh, uncontended container (`docker run` instead of `docker exec` into the already-running `coolify-vite`) only produced a brief speed burst before reverting to the same crawl — confirming the bottleneck is the WSL2/AV I/O tax itself, not process contention with the dev server.

**Workaround: run the build natively on the Windows host instead**, bypassing the WSL2 VM boundary entirely:

```powershell
yarn build
```

This will fail out of the box with `Cannot find module '@rolldown/binding-win32-x64-msvc'` — this repo's `yarn.lock` already resolves that optional native binding (rolldown's Windows binary), but `node_modules` here was originally installed *inside* the Linux container, so only the Linux bindings (`binding-linux-x64-gnu`, `binding-linux-x64-musl`) were ever fetched. Fetch the missing one once:

```powershell
yarn add -D @rolldown/binding-win32-x64-msvc@<rolldown version, e.g. 1.1.4>
```

Then revert `package.json`'s diff (`git checkout -- package.json`) — this binding is a Windows-host-only workaround for local verification, not a real project dependency (the app builds inside Linux containers in Docker/CI, where the Linux bindings already work fine); `node_modules/@rolldown/binding-win32-x64-msvc` itself is untracked and safe to leave in place for next time. With the binding present, a native `yarn build` completes in **under 10 seconds** (vs. 3+ hours through the container), since it reads NTFS directly with no VM boundary and no cross-filesystem AV scanning per file.

The long-term fix (not applied — out of scope for a single session) is moving the repo into the WSL2 filesystem itself (e.g. a native WSL2 distro path) rather than bind-mounting from `C:\Users\...`, which eliminates the 9P bridge for every container, not just Vite builds.

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
