# Developing Coolify In Containers (Windows)

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 22, 2026**

**The development environment for this project is Ubuntu Linux.** This guide covers the one host-specific concern: bootstrapping that Linux environment on a Windows machine via WSL2. If you're on native Linux (or macOS), you don't need this document — clone the repo, `cp .env.development.example .env`, and `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d`; every command in `docs/command.md` runs identically.

**The repo lives inside a WSL2 Linux distro's native filesystem, not under `C:\Users\...`.** Docker Desktop's WSL2 backend bridges Windows and Linux file access over a 9P protocol layer that is extremely slow for anything bind-mounted from an NTFS path (a `yarn build` that takes ~2 seconds from WSL2-native storage took over 3 hours from a Windows path — see `docs/command.md`'s "RESOLVED" section for the full root-cause writeup). Keeping the working tree on WSL2-native storage (e.g. `/root/projects/coolify-full`) avoids that bridge entirely for every container operation, not just builds.

## 0. 5-Command Quick Start

Run these from a WSL2 terminal (`wsl` from PowerShell, or the integrated terminal in a VS Code window connected via the Remote - WSL extension), inside the repo's WSL2 path:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps
curl -s http://localhost:8000/api/health
docker exec coolify sh -lc "cd /var/www/html && php artisan about"
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Feature"
```

Open after startup (these ports are exposed to the Windows host, so plain `localhost` URLs work from a Windows browser):

- App: `http://localhost:8000`
- Vite: `http://localhost:5173`

## 1. What Runs Where

- Your code lives inside a WSL2 distro (e.g. `/root/projects/coolify-full`), not on the Windows `C:` drive
- Docker Desktop's WSL2 backend runs the Linux containers directly against that same WSL2 filesystem — no cross-boundary bridge for bind mounts
- The main app container is `coolify`
- Edit the files with VS Code's **Remote - WSL** extension connected to the same distro (`code .` from inside a WSL2 terminal, or "WSL: Connect to WSL" from the Command Palette) so the editor and the containers see identical files. A VS Code window opened directly on the Windows path is a different, stale copy — don't edit there.

## 2. One-Time Setup

1. Install a real WSL2 distro if you don't already have one: `wsl --install -d Ubuntu` (avoid storing the project in Docker Desktop's own internal `docker-desktop` distro — it can be wiped/reset on Docker Desktop updates).
2. Install Docker Desktop, then enable WSL2 integration for that distro: **Settings → Resources → WSL Integration**, toggle the distro on, Apply & Restart.
3. Get the repo onto the WSL2 filesystem (clone directly there, or `rsync` an existing Windows-path checkout across — see `docs/command.md` for the exact `rsync` flags and a mistake to avoid with unanchored `--exclude` patterns).
4. From a WSL2 terminal, in the repo root, create `.env` from the development template if needed:

```bash
cp .env.development.example .env
```

That's the whole one-time setup — no manual `composer install`, `key:generate`, or seeding. On the **first** `docker compose up -d` (Section 3):

- The 3 repo-built images (`coolify:dev`, `coolify-realtime:dev`, `coolify-testing-host:dev`) build automatically from the Dockerfiles under `docker/`; everything else is pulled.
- The `coolify-vite` container runs `yarn install` on startup.
- The `coolify` container's init service runs `composer install`, `php artisan migrate`, and `php artisan dev --init` — which generates `APP_KEY` (the `.env` template ships it empty), creates the `storage` symlink, and seeds the database on first boot (later boots detect the initialized instance and skip seeding).

Once healthy, log in at `http://localhost:8000` with the dev seed account: **`test@example.com` / `password`**.

Note: an extra `coolify-proxy` (Traefik) container appears later at runtime — the app itself creates it when the localhost server's proxy starts. It is not part of the Compose stack and won't exist right after `up -d`; that's normal.

## 3. Start The Stack

Run from a WSL2 terminal, in the repo root:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

Check status:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps
```

Expected services: `coolify` (app), `coolify-db` (Postgres), `coolify-redis` (Redis), `coolify-realtime`, `coolify-vite`, plus `coolify-mail`, `coolify-minio`, `coolify-testing-host`.

If you also have an old stack running from a Windows-path checkout, bring that one down first (`docker compose -f docker-compose.yml -f docker-compose.dev.yml down` from the old location) — both would otherwise fight over the same container/network names.

## 4. Daily Workflow

1. Start Docker Desktop.
2. Start the stack from a WSL2 terminal.
3. Edit code in a VS Code window connected via Remote - WSL to the same distro.
4. Run commands inside the app container.
5. Run tests.
6. Format changed PHP files.

## 5. Run Commands Inside The Container

Use this pattern, from a WSL2 terminal:

```bash
docker exec coolify sh -lc "cd /var/www/html && <command>"
```

Examples:

```bash
docker exec coolify sh -lc "cd /var/www/html && php artisan about"
docker exec coolify sh -lc "cd /var/www/html && php artisan migrate"
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact"
docker exec coolify sh -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

See `docs/command.md` for the full command reference (Artisan, Pint, PHPStan, Pest, Yarn/Vite, Horizon, logs).

## 6. Dependencies

### PHP dependencies

Run Composer in the container:

```bash
docker exec coolify sh -lc "cd /var/www/html && composer install"
docker exec coolify sh -lc "cd /var/www/html && composer dump-autoload"
```

### JavaScript dependencies

`coolify-vite`'s container command already runs `yarn install` automatically on `spin up` / `docker compose up -d` — you generally don't need to install manually. If you do:

```bash
docker exec coolify-vite yarn install
```

For browser tests, also install Playwright inside the container:

```bash
docker exec coolify sh -lc "cd /var/www/html && npm install playwright && npx playwright install"
```

## 7. Running Tests

Use folder scoping when you want to avoid browser tests:

```bash
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Feature"
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Unit"
```

Run a specific filter:

```bash
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Feature --filter=SomeTestName"
```

## 8. Useful Logs And Debugging

Tail app logs:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f coolify
```

Tail Vite logs:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f coolify-vite
```

## 9. Common Issues

### A window connected to the Windows-path copy shows no changes

You have a VS Code window open on `C:\Users\...` instead of the WSL2 path. Close it and reopen via Remote - WSL (see Section 1) — the two are different, unrelated directories even if one started as a copy of the other.

### `docker exec coolify vendor/bin/pint` (or similar) fails with a permissions/"not writable" error

New files created via some editors/tools land with restrictive permissions (`rw-r--r--`) that the container's runtime user can't write to, unlike the rest of the tree (`rwxrwxrwx`, carried over from the original `rsync` migration). Fix with `chmod 777 <file>` on the WSL2 side before retrying.

### Connecting VS Code to the Ubuntu WSL2 repo (after a reboot, or a fresh VS Code install)

Recommended: do this entirely from VS Code's UI, not the terminal — it's more reliable and avoids a WSL first-run quirk described below.

1. Open VS Code normally (Start Menu, taskbar, desktop icon — a plain Windows-side window is fine as a starting point).
2. `Ctrl+Shift+P` → run **`WSL: Connect to WSL using Distro...`**.
3. Pick **`Ubuntu`** from the list (shown as the default distro).
4. A new window opens; confirm the bottom-left corner shows a blue `>< WSL: Ubuntu` badge.
5. `File → Open Folder` → `/root/projects/coolify-full`.
6. Once open, verify the Explorer sidebar shows the full repo tree, not just a couple of top-level folders (see the Explorer-stuck note below).

After this, the window shows up in `File → Open Recent`, so future reconnects can skip straight to step 2/6 without repeating the distro picker.

Alternative (terminal-based): from a WSL2 shell, `cd /root/projects/coolify-full && code .` also opens a pre-connected window. Only use this if you already have a working WSL2 terminal — running `wsl -d Ubuntu` (or opening the Store "Ubuntu" app) to get one can trigger a **"Provisioning the new WSL instance" / "Create a default Unix user account" prompt** even on an existing, populated distro, if that distro was never launched through that particular first-run wizard before (e.g. it was originally set up via import and only ever used as `root`). This does **not** wipe existing data — the wizard only adds a login user — but it's a confusing detour. Prefer the Command Palette method above to sidestep it entirely. If you do hit that prompt, it's safe to complete it (pick any username/password); verify with `sudo ls /root/projects/coolify-full` afterward.

If the Explorer sidebar looks stuck showing only a couple of top-level folders (e.g. only `.agents` and `.circleci`) right after connecting, the tree usually just hasn't finished loading — wait a few seconds, then try `Developer: Reload Window` from the Command Palette if it's still incomplete.

### PHP/Laravel-aware extensions report "PHP executable not found" or can't detect the Laravel framework

These extensions (e.g. DEVSense's Composer/PHP Debugger extensions, `ryannaddy.laravel-artisan`) look for a PHP binary and a runnable `php artisan` directly on the WSL host — but per this project's setup, PHP only runs correctly *inside* the `coolify` container (the host has no `pdo_pgsql` etc.). Two-part fix:

1. Install a host-level PHP CLI purely so extensions have something to detect (this does not change how the project itself runs — that's still always via `docker exec coolify ...`):
   ```bash
   sudo apt install -y php-cli
   ```
2. For extensions that actually try to execute `php artisan` (like `ryannaddy.laravel-artisan`), point them at the container instead of the host binary via `.vscode/settings.json`:
   ```json
   "artisan.docker.enabled": true,
   "artisan.docker.command": "docker exec coolify"
   ```
Reload the window (`Developer: Reload Window`) after either change for it to take effect.

### `git push`/`git pull` fails with `could not read Username for 'https://github.com'`

WSL-side git has no credential helper by default — GitHub credentials live in Windows' Git Credential Manager. Wire WSL git to it (already configured in this repo's local git config):

```bash
git config credential.helper '/mnt/c/Program\ Files/Git/mingw64/bin/git-credential-manager.exe'
```

### `docker exec coolify git status` returns nothing / `vendor/bin/pint --dirty` finds nothing despite real changes

The container's git checkout of the bind-mounted tree can hit a `safe.directory`/ownership mismatch that makes `git` silently no-op inside the container, even though `git status` on the WSL2 host side works fine. Work around it by passing explicit file paths to Pint/PHPStan instead of relying on `--dirty` / uncommitted-file autodetection.

### Playwright is missing

Install browser test dependencies inside the container (see Section 6).

### `Undefined type 'Log'` in the editor

Use an explicit import:

```php
use Illuminate\Support\Facades\Log;
```

### Docker compose warns about `PUSHER_HOST` or `PUSHER_PORT`

These are optional environment values and do not always block local development.

### A "Cannot connect to real-time service" popup appears, or Vite HMR never connects

Confirmed 2026-07-19: not a bug in this app. The Soketi WebSocket server (port 6001) and Vite's own dev-server HMR socket (port 5173) are two completely independent services, built with different code — if both fail with the identical browser error (`WebSocket connection to '...' failed: WebSocket is closed before the connection is established`, visible in DevTools → Console), the common factor is something in the browser sitting between it and `localhost`, not either service. Confirmed live: a real WebSocket handshake to `localhost:6001` from outside the browser (`curl` with proper `Upgrade`/`Sec-WebSocket-*` headers) succeeds cleanly, and both failures disappeared immediately after disabling an ad blocker for the site. If you hit this, check browser extensions (ad blockers/privacy extensions are the most common cause) before assuming the app is broken — try an Incognito/Private window (extensions off by default) as the fastest test.

### After a Windows reboot, `coolify` comes up unhealthy with `artisan` missing

Confirmed 2026-07-20: a Docker Desktop/WSL2 filesystem-readiness race, not an app bug. Right after a host reboot, the `coolify` container can start before WSL2's filesystem is fully ready, so its bind mount to the repo (`.:/var/www/html`) attaches empty — `docker exec coolify ls /var/www/html` shows just an empty `storage/` directory, no `artisan`, and every scheduled/queue process loops on `Could not open input file: artisan`. A plain `docker restart coolify` (once WSL2 has actually settled) re-attaches the real mount and fixes it immediately — confirmed live.

`docker-compose.dev.yml` runs a `willfarrell/autoheal` sidecar (`autoheal` service) that watches for any container labeled `autoheal=true` — currently just `coolify` — going `unhealthy`, and restarts it automatically. This alone was verified end-to-end once with a real controlled test (forcing `coolify`'s healthcheck to fail via `s6-svc -d /run/service/nginx`, confirming a real unattended recovery).

**Correction, 2026-07-22 (two real reboots, not a synthetic test):** autoheal alone is not sufficient in practice. On two separate real reboots — including one *after* enabling Docker Desktop's own "start on sign-in" setting, which does eliminate the "Docker isn't even running yet" case but not this one — the same race hit multiple containers simultaneously, including `coolify-autoheal` itself (`Exited (127)`), along with `mailpit`/`minio`/`vite`/`testing-host`, none of which had any `restart:` policy set at all (silent gap — they simply never self-heal from any crash, reboot-related or not). With the very sidecar meant to fix `coolify` also knocked out by the same root cause, nothing was actually auto-recovering; manual intervention (`docker compose up -d` + `docker restart coolify`) was needed both times.

**Correction, 2026-07-22 (third real reboot — `mount-doctor` itself failed):** a container-native fix (`mount-doctor`, a sidecar with `restart: always` and no bind mount of its own except the Docker socket) was built and shipped on the theory that a socket-only container would be immune to the race. A third real reboot disproved this: `mount-doctor` came up `Exited (127)`, same as `coolify-autoheal`, `coolify-realtime` (soketi), and `coolify-testing-host`. `docker inspect` on all four showed the *actual* mechanism is broader than "directory bind mount attaches empty" — it's two distinct failure shapes:
- **Directory** bind mounts (`coolify`'s `.:/var/www/html`) attach empty — the container starts, just with nothing in it.
- **File** bind mounts (`/var/run/docker.sock`, or soketi's individual `terminal-server.js`/`terminal-utils.js`) fail *harder*: the container's OCI creation itself errors (`mounting a directory onto a file (or vice-versa)`), so the container never starts at all.

`mount-doctor` needs `docker.sock` — a file mount — to do anything at all, so it's a victim of the exact failure it exists to repair. This isn't fixable by editing the container's script: nothing running *inside* a container can recover from a failure that happens *before* that container can be created. Only code running outside the container runtime is immune, since it talks to the already-running `dockerd` directly instead of needing its own mount. `mount-doctor` has been removed.

**Actual fix now in place, two parts:**
1. `mailpit`, `minio`, `vite`, and `testing-host` have `restart: unless-stopped` in `docker-compose.dev.yml` (previously unset, defaulting to Docker's `no` — a real robustness gap independent of this specific bug).
2. **`./scripts/dev-up.sh` (run this after logging back in following a reboot)** — host-side, so it's immune to the file-mount OCI-creation race that killed the container-native attempt. Detects and fixes both failure shapes: retries `docker restart coolify` until its directory mount is populated, and separately retries `docker restart` on `coolify-realtime`/`coolify-autoheal`/`coolify-testing-host` until each reports `running`. In real testing the file-mount race took up to ~10 minutes post-boot to clear on its own, so the script retries with real persistence (up to 3 minutes per service) rather than giving up after a few seconds.

A Windows Task Scheduler entry to run this automatically at login was considered and deliberately rejected earlier in favor of a container-native approach, on portability grounds — that approach turned out to be a technical dead end for this specific race, so for now this remains a one-command manual step after a reboot: `./scripts/dev-up.sh`.

### A brand-new component/file doesn't show up in the browser, even after editing it a few times

Confirmed 2026-07-20: a Vite HMR limitation, not a bug in whatever you just wrote. Hot Module Replacement can only *patch* modules already loaded in the browser's current module graph — a file being imported for the first time (e.g. a new `Components/Foo.jsx` referenced from an existing file) doesn't have a clean hot-add path, so the update may not propagate to an already-open tab even after several edits. `docker logs coolify-vite` will show HMR events firing for the *importing* file but nothing for the new file itself. Fix: a full page reload (not just waiting) — Vite's dev server always serves current file content on a fresh request regardless of HMR state, so a reload reliably picks up the new module. Confirmed with a real case: adding `Components/Toast.jsx` and importing it from `AppLayout.jsx` needed a hard reload to actually render, despite 3 HMR updates firing for `AppLayout.jsx` itself in the meantime. Dev-only — production serves one pre-built bundle via `yarn build`, no HMR involved, so this can't happen for real users.

### App health stays `starting`

Wait 20 to 60 seconds, then check status again:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps
```

### `service "redis" has neither an image nor a build context specified`

You ran `docker compose -f docker-compose.dev.yml` without the base `docker-compose.yml`. `docker-compose.dev.yml` is an override, not a standalone file — always pass both (see `docs/command.md`).

## 10. Stop, Restart, Reset

Stop containers:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml down
```

Reset data and restart:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml down -v
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

Then run migrations and seeders:

```bash
docker exec coolify sh -lc "cd /var/www/html && php artisan migrate:fresh --seed"
```

## 11. Scan Other Folders

Use these when you want to inspect a specific part of the repository. Run from a WSL2 terminal, either on the host (if you have PHP available there) or inside the container (prefix with `docker exec coolify sh -lc "cd /var/www/html && ..."`).

### Syntax checks

```bash
find app routes database tests -name '*.php' -exec php -l {} \;
```

### Static analysis

```bash
vendor/bin/phpstan analyse app --memory-limit=1G
vendor/bin/phpstan analyse routes --memory-limit=1G
vendor/bin/phpstan analyse database --memory-limit=1G
vendor/bin/phpstan analyse tests --memory-limit=1G
```

### What each folder is for

- `app`: actions, jobs, models, services, listeners, notifications
- `routes`: web, API, console, and channel routes
- `database`: migrations, seeders, and factories
- `tests`: feature, unit, and browser tests
- `config`: configuration files; syntax validation is enough for most files
- `resources/views`: Blade syntax and editor diagnostics
- `resources/js`: use `yarn build` or the Vite dev server

Start with the smallest folder that matches the code you changed, then widen the scan if needed.
