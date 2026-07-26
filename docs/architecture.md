# Architecture Overview

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 26, 2026**

This document explains how this repository is actually put together — verified against the real folder structure, config files, and code, not a generic description of what a Coolify-like app "usually" looks like.

Everything described here runs on **Linux end-to-end**: the development environment is Ubuntu (via WSL2 on a Windows host), every service is a Linux container on a Linux filesystem, and the whole stack behaves identically on a native Linux machine.

For the frontend migration specifically (Livewire → Inertia.js/React), see [livewire-to-react-migration.md](livewire-to-react-migration.md) — that document is the detailed, phase-by-phase source of truth for anything frontend-related and is not repeated here.

## 1. What this is

Coolify is a self-hostable PaaS: a Laravel application that manages servers, applications, databases, and services by connecting to them over SSH — there is no separate remote "agent" service running as part of this codebase. The one exception is **Sentinel**, a small metrics-collection binary Coolify installs on managed servers (via `CheckAndStartSentinelJob`) so the dashboard can show CPU/memory/disk graphs without polling over SSH for every metric; it's an installed artifact on the remote host, not a folder in this repo.

## 2. Repository structure

Verified against the actual top-level layout:

```text
coolify-full/
├── app/                    # Laravel application code (see Section 3)
├── bootstrap/               # App bootstrap + global helper files (bootstrap/helpers/*.php)
├── config/                  # Laravel + Coolify configuration
├── database/                 # Migrations, seeders, factories
├── docker/                   # Dockerfiles for coolify-helper, coolify-realtime, dev/prod/testing-host images
├── docs/                     # This folder
├── public/                   # Web root, compiled assets land in public/build
├── resources/                 # Frontend: css/, fonts/, js/, views/ (see Section 4)
├── routes/                    # web.php, api.php, console.php, channels.php
├── scripts/                   # Shell scripts (install/upgrade scripts, helper image build scripts)
├── storage/                   # Logs, compiled views, framework cache
├── svgs/                       # SVG icon assets used by the UI (also mirrored under public/svgs)
├── templates/                  # Coolify's built-in service templates (one-click service defaults, service-templates*.json)
├── tests/                      # Pest/PHPUnit tests — see tests/README.md for the test-infrastructure files specifically
├── docker-compose.yml, docker-compose.dev.yml, docker-compose.prod.yml, docker-compose.windows.yml
├── .circleci/config.yml         # CircleCI pipeline
└── .github/                     # GitHub Actions pipelines + CodeQL config (see Section 7)
```

There is no `agents/` directory and no separate agent codebase in this repository.

## 3. Backend (`app/`)

- **`Actions/`** — domain actions using `lorisleiva/laravel-actions`, organized by area: `Application/`, `Database/`, `Docker/`, `Proxy/`, `Server/`, `Service/`, `Shared/`, `CoolifyTask/`, `Fortify/`, `User/`. `CoolifyTask/RunRemoteProcess.php` is the action that actually runs commands on remote servers over SSH (via the `instant_remote_process()` / `SshMultiplexingHelper` helpers in `bootstrap/helpers/remoteProcess.php`) — this is the real "remote execution" layer, not a separate agent process. There is no `Stripe/` subfolder — this fork removed the Stripe/subscription billing subsystem entirely (see [todo.md](../todo.md)).
- **`Http/Controllers/`** — REST API controllers (`Api/`) plus the full set of Inertia page controllers created during the React migration (see the migration doc). There is no `Livewire/` directory — the migration completed 2026-07-14 and `app/Livewire/` was deleted once empty; every full-page route is now Inertia/React.
- **`Models/`** — Eloquent models (`Server`, `Application`, `Service`, `Project`, `Team`, standalone database models, etc.).
- **`Jobs/`** — queued work: deployments (`ApplicationDeploymentJob`), backups, Docker cleanup, and periodic checks like `CheckAndStartSentinelJob`, `CheckForUpdatesJob`. Runs on Redis-backed queues via Horizon.
- **`Services/`** — orchestration/business logic (`ConfigurationGenerator`, `DockerImageParser`, `ContainerStatusAggregator`, `HetznerService`, etc.).
- **`Policies/`** — authorization, registered in `AuthServiceProvider`.

## 4. Frontend (`resources/`)

```text
resources/
├── css/
├── fonts/
├── js/
│   ├── Layouts/     # React persistent layouts (Inertia)
│   ├── Pages/       # React page components (Inertia), path mirrors the old Livewire namespace (kept for continuity)
│   ├── app.js       # Near-empty entrypoint for the few remaining plain-Blade pages (guest/auth, errors)
│   └── inertia-app.jsx  # Inertia/React entrypoint (everything else)
└── views/           # Blade templates — the Inertia root view plus a handful of plain guest/auth/error pages
```

The migration to a single frontend stack completed 2026-07-14:

- **Inertia.js + React 19** — every full-page route and all navigation/chrome infrastructure. See [livewire-to-react-migration.md](livewire-to-react-migration.md) for the full phase-by-phase log, the reasoning for choosing Inertia over a plain SPA + API (the short version: Inertia was chosen specifically so we *don't* have to build and version a separate REST API for a full SPA), and the conversion recipe used for each page.
- Livewire and Alpine.js are both fully removed from `composer.json`/`package.json` — no Livewire components remain anywhere in the app.

Tailwind CSS v4, Monaco Editor (code editor), and XTerm.js (terminal) round out the frontend dependencies — see [TECH_STACK.md](../TECH_STACK.md) for the full list.

## 5. Deployment flow

A deployment does not go through a separate agent service — it's a Laravel job that SSHes into the target server directly:

1. A deployment is triggered (push webhook, manual redeploy, or scheduled).
2. `ApplicationDeploymentJob` is queued (Redis + Horizon).
3. The job builds and runs shell commands on the target server via `instant_remote_process()` (SSH, with connection multiplexing to avoid re-authenticating per command).
4. Container/build status updates are broadcast over Soketi (WebSockets) so Inertia/React pages update in real time via `ApplicationStatusChanged`/`ServiceStatusChanged`/`ProxyStatusChanged` events.
5. Server-side metrics (CPU/memory/disk) come from the optional Sentinel binary installed on the remote server, polled/displayed via `Server\Sentinel\*`.

## 6. Docker & environments

| File | Purpose |
| --- | --- |
| `docker-compose.yml` | Base/production service definitions |
| `docker-compose.dev.yml` | Local development override — adds `postgres`, `redis`, `soketi`, `vite`, `testing-host`, `mailpit`, `minio`, `autoheal`, `stray-pruner` alongside the `coolify` app container. See [`docs/command.md`](command.md) for what each one actually does |
| `docker-compose.prod.yml` | Production-specific overrides |
| `docker-compose.windows.yml` | Windows Docker Desktop-specific overrides |
| `docker/` | Dockerfiles for the `coolify-helper` and `coolify-realtime` images, plus dev/prod/testing-host variants |

The database is **PostgreSQL** (`config/database.php` defaults `DB_CONNECTION` to `pgsql`), not MySQL. Redis backs caching, queues, and Horizon. Soketi is the WebSocket server for real-time broadcasting.

See [DEVELOPING_IN_CONTAINERS_WINDOWS.md](../DEVELOPING_IN_CONTAINERS_WINDOWS.md) for the actual day-to-day local dev workflow used on this machine.

## 7. CI

- **`.circleci/config.yml`** — CircleCI pipeline.
- **`.github/workflows/quality.yml`** — GitHub Actions: PHPStan, Psalm (`--taint-analysis`, PHP-side security dataflow scanning), the Pest suite, Vitest, an `html-validate` HTML5-structural-validity scan of the rendered `errors/*.blade.php` views (via `app:snapshot-error-pages`), and a Prettier format check.
- **`.github/workflows/codeql.yml`** + **`.github/codeql/codeql-config.yml`** — GitHub Actions: CodeQL, scoped to `javascript` only (CodeQL has no PHP support — Psalm's taint analysis above is the PHP-side equivalent). See [`todo.md`](../todo.md)'s "GitHub repo-level security features" entry for why two tools were needed and what each one actually covers.

## 8. Where to go next

- Frontend migration status, rationale, and per-phase verification log: [livewire-to-react-migration.md](livewire-to-react-migration.md)
- Full technology stack list: [TECH_STACK.md](../TECH_STACK.md)
- Local dev environment setup: [DEVELOPING_IN_CONTAINERS_WINDOWS.md](../DEVELOPING_IN_CONTAINERS_WINDOWS.md)
- Test infrastructure (`TestCase.php`, `Pest.php`, etc.): [tests/README.md](../tests/README.md)
