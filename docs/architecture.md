# Architecture Overview

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: August 5, 2026**

This document explains how this repository is actually put together — verified against the real folder structure, config files, and code, not a generic description of what a Coolify-like app "usually" looks like.

Everything described here runs on **Linux end-to-end**: the development environment is Ubuntu (via WSL2 on a Windows host), every service is a Linux container on a Linux filesystem, and the whole stack behaves identically on a native Linux machine.

For the frontend migration specifically (Livewire → Inertia.js/React), see [livewire-to-react-migration.md](livewire-to-react-migration.md) — that document is the detailed, phase-by-phase source of truth for anything frontend-related and is not repeated here.

## 1. What this is

Coolify is a self-hostable PaaS: a Laravel application that manages servers, applications, databases, and services by connecting to them over SSH — there is no separate remote "agent" service running as part of this codebase. The one exception is **Sentinel**, a small metrics-collection binary Coolify installs on managed servers (via `CheckAndStartSentinelJob`) so the dashboard can show CPU/memory/disk graphs without polling over SSH for every metric; it's an installed artifact on the remote host, not a folder in this repo.

For a server with no public IP (behind NAT, a home network, etc.), direct SSH isn't an option — **Cloudflare Tunnel** support (`ServerCloudflareTunnelController`, `app/Actions/Server/ConfigureCloudflared.php`) routes SSH through a `cloudflared` container on that server instead, registered against Cloudflare's edge network. Manual (flag-only) and automated (real tunnel token, provisions the container over the existing SSH connection first) configuration modes are both supported; see `docs/smoketest.md` for the real end-to-end verification against a live Cloudflare account.

## 2. Monolith, not microservices

This is a deliberate choice, not a default. The strongest evidence is already in the codebase: the one piece that genuinely needed to be a separate process — `coolify-realtime` (WebSocket broadcasting + SSH terminal streaming, Section 7) — already is, running as its own Node service. Everything else lives in the single Laravel app.

**Why it stays that way:**

- **The domain is tightly coupled, not naturally service-shaped.** Servers, applications, deployments, and proxy configuration all reference each other constantly through the same database (a deployment reads its application, which reads its server, which reads its proxy config, in the same request/job). Splitting these into separate services would mean either replicating that state across service boundaries or replacing fast, transactional Eloquent relations with network calls and eventual consistency — added complexity with no corresponding capability gained.
- **The usual microservices motivations don't apply to this deployment model.** Coolify is self-hosted and single-instance: one install manages N remote servers over SSH, not a horizontally-scaled multi-tenant SaaS. Independent scaling and independent deployment — the two strongest real-world reasons to split a service out — only pay off when different parts of a system have genuinely different load profiles or release cadences. Here, the whole app scales as one unit because it's a single admin's control plane, not a system serving variable, uncorrelated traffic across components.
- **Team-ownership boundaries don't exist here.** Microservices also earn their keep by letting separate teams own separate services independently. This is a single-maintainer project — there's no ownership boundary to formalize.
- **Upstream precedent agrees.** The real coollabsio/coolify project this fork is based on is also a monolith. That's a meaningful signal from a team that has iterated on this exact domain for longer than this fork has existed.

**The trade-off, stated plainly:** a monolith couples deployment (every change ships the whole app together) in exchange for avoiding the operational cost of running, versioning, and coordinating multiple deployables — extra network hops, distributed-transaction complexity, service discovery, and more infrastructure to keep healthy. For a single-instance, self-hosted tool with tightly coupled domain state, that trade favors the monolith. If this project's shape ever changed — multi-tenant SaaS, horizontally-scaled workers, separate teams — this conclusion would be worth revisiting, but nothing about the current architecture points that direction.

## 3. Repository structure

Verified against the actual top-level layout:

```text
coolify-full/
├── app/                    # Laravel application code (see Section 4)
├── bootstrap/               # App bootstrap + global helper files (bootstrap/helpers/*.php)
├── config/                  # Laravel + Coolify configuration
├── database/                 # Migrations, seeders, factories
├── docker/                   # Dockerfiles for coolify-helper, coolify-realtime, dev/prod/testing-host images
├── docs/                     # This folder
├── public/                   # Web root, compiled assets land in public/build
├── resources/                 # Frontend: css/, fonts/, js/, views/ (see Section 5)
├── routes/                    # web.php, api.php, console.php, channels.php
├── scripts/                   # Shell scripts (install/upgrade scripts, helper image build scripts)
├── storage/                   # Logs, compiled views, framework cache
├── svgs/                       # SVG icon assets used by the UI (also mirrored under public/svgs)
├── templates/                  # Coolify's built-in service templates (one-click service defaults, service-templates*.json)
├── tests/                      # Pest/PHPUnit tests — see tests/README.md for the test-infrastructure files specifically
├── docker-compose.yml, docker-compose.dev.yml, docker-compose.prod.yml, docker-compose.windows.yml
├── .circleci/config.yml         # CircleCI pipeline
└── .github/                     # GitHub Actions pipelines + CodeQL config (see Section 8)
```

There is no `agents/` directory and no separate agent codebase in this repository.

## 4. Backend (`app/`)

- **`Actions/`** — domain actions using `lorisleiva/laravel-actions`, organized by area: `Application/`, `Database/`, `Docker/`, `Proxy/`, `Server/`, `Service/`, `Shared/`, `CoolifyTask/`, `Fortify/`, `User/`. `CoolifyTask/RunRemoteProcess.php` is the action that actually runs commands on remote servers over SSH (via the `instant_remote_process()` / `SshMultiplexingHelper` helpers in `bootstrap/helpers/remoteProcess.php`) — this is the real "remote execution" layer, not a separate agent process. There is no `Stripe/` subfolder — this fork removed the Stripe/subscription billing subsystem entirely (see [todo.md](../todo.md)).
- **`Http/Controllers/`** — REST API controllers (`Api/`) plus the full set of Inertia page controllers created during the React migration (see the migration doc). There is no `Livewire/` directory — the migration completed 2026-07-14 and `app/Livewire/` was deleted once empty; every full-page route is now Inertia/React.
- **`Models/`** — Eloquent models (`Server`, `Application`, `Service`, `Project`, `Team`, standalone database models, etc.).
- **`Jobs/`** — queued work: deployments (`ApplicationDeploymentJob`), backups, Docker cleanup, and periodic checks like `CheckAndStartSentinelJob`, `CheckForUpdatesJob`. Runs on Redis-backed queues via Horizon.
- **`Services/`** — orchestration/business logic (`ConfigurationGenerator`, `DockerImageParser`, `ContainerStatusAggregator`, etc.).
- **`Policies/`** — authorization, registered in `AuthServiceProvider`.

## 5. Frontend (`resources/`)

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

## 6. Deployment flow

A deployment does not go through a separate agent service — it's a Laravel job that SSHes into the target server directly:

1. A deployment is triggered (push webhook, manual redeploy, or scheduled).
2. `ApplicationDeploymentJob` is queued (Redis + Horizon).
3. The job builds and runs shell commands on the target server via `instant_remote_process()` (SSH, with connection multiplexing to avoid re-authenticating per command).
4. Container/build status updates are broadcast over Soketi (WebSockets) so Inertia/React pages update in real time via `ApplicationStatusChanged`/`ServiceStatusChanged`/`ProxyStatusChanged` events.
5. Server-side metrics (CPU/memory/disk) come from the optional Sentinel binary installed on the remote server, polled/displayed via `Server\Sentinel\*`.

## 7. Docker & environments

| File | Purpose |
| --- | --- |
| `docker-compose.yml` | Base/production service definitions |
| `docker-compose.dev.yml` | Local development override — adds `postgres`, `redis`, `soketi`, `vite`, `testing-host`, `mailpit`, `minio`, `autoheal`, `stray-pruner` alongside the `coolify` app container. See [`docs/command.md`](command.md) for what each one actually does |
| `docker-compose.prod.yml` | Production-specific overrides |
| `docker-compose.windows.yml` | Windows Docker Desktop-specific overrides |
| `docker-compose.smoketest.yml` | Opt-in — a genuinely isolated "remote server" (its own real `dockerd`) for smoke-testing destructive Docker/OS actions safely. Not started by `spin up`. See [`docs/command.md`](command.md) |
| `docker-compose.https.yml` | Opt-in — TLS termination for a friendly local dev URL (`https://coolify-full.localhost:8443`). Not started by `spin up`. See [`docs/command.md`](command.md) |
| `docker/` | Dockerfiles for the `coolify-helper` and `coolify-realtime` images, plus dev/prod/testing-host/smoketest-host variants |

The database is **PostgreSQL** (`config/database.php` defaults `DB_CONNECTION` to `pgsql`), not MySQL. Redis backs caching, queues, and Horizon. Soketi is the WebSocket server for real-time broadcasting.

See [DEVELOPING_IN_CONTAINERS_WINDOWS.md](../DEVELOPING_IN_CONTAINERS_WINDOWS.md) for the actual day-to-day local dev workflow used on this machine.

## 8. CI

- **`.circleci/config.yml`** — CircleCI pipeline.
- **`.github/workflows/quality.yml`** — GitHub Actions: PHPStan, Psalm (`--taint-analysis`, PHP-side security dataflow scanning), the Pest suite, Vitest, an `html-validate` HTML5-structural-validity scan of the rendered `errors/*.blade.php` views (via `app:snapshot-error-pages`), and a Prettier format check.
- **`.github/workflows/codeql.yml`** + **`.github/codeql/codeql-config.yml`** — GitHub Actions: CodeQL, scoped to `javascript` only (CodeQL has no PHP support — Psalm's taint analysis above is the PHP-side equivalent). See [`todo.md`](../todo.md)'s "GitHub repo-level security features" entry for why two tools were needed and what each one actually covers.

## 9. Where to go next

- Frontend migration status, rationale, and per-phase verification log: [livewire-to-react-migration.md](livewire-to-react-migration.md)
- Full technology stack list: [TECH_STACK.md](../TECH_STACK.md)
- Local dev environment setup: [DEVELOPING_IN_CONTAINERS_WINDOWS.md](../DEVELOPING_IN_CONTAINERS_WINDOWS.md)
- Test infrastructure (`TestCase.php`, `Pest.php`, etc.): [tests/README.md](../tests/README.md)
