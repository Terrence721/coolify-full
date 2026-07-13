# 🚀 Coolify-Full (Enhanced Fork) — Senior Full-Stack Engineering Demonstration

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 13, 2026**

This repository is a professionally enhanced fork of [Coolify](https://coolify.io), created to demonstrate senior full-stack engineering capabilities across frontend modernization, backend engineering, and containerized infrastructure.

It showcases real-world engineering work including:

- Migrating a legacy Laravel Livewire UI to Inertia.js + React, page by page, with every phase documented and verified
- Removing the commercial/billing surface area to produce a clean, self-hosted-only fork
- Working inside — and being honest about the constraints of — a large, real-world Laravel monolith rather than a greenfield rewrite

This project is not affiliated with the Coolify team and is intended solely as a technical portfolio artifact.

---

## 🧭 Why This Project Matters

Rewriting a UI from scratch is easy when there's no existing app to keep working. This project demonstrates the harder, more common real-world task: modernizing a live, actively-used Laravel application's frontend **without a big-bang rewrite** — converting one page at a time, verifying each conversion automatically, and keeping a running audit trail a reviewer can actually check.

**Incremental modernization, not a rewrite**  
The original Coolify UI is built on Blade, Livewire, and Alpine.js. Rather than discarding that and building a separate SPA, this fork adopts **Inertia.js**: converted pages become React components rendered through the same Laravel routes, while unconverted pages stay on Livewire. Both stacks coexist in the same app throughout the migration — see [`docs/livewire-to-react-migration.md`](docs/livewire-to-react-migration.md) for the full, phase-by-phase log (page inventory, conversion recipes, what was verified and how).

**Why Inertia over a decoupled SPA + API**  
A plain React SPA would require designing and versioning a whole new API surface before a single page could move. Inertia avoids that: each migrated page stays a normal Laravel route/controller returning props, so migrated and not-yet-migrated pages coexist under the same app, and Laravel's existing routing, auth, CSRF, and session handling keep working unchanged.

**De-commercialization**  
This fork also strips the SaaS/billing surface area from upstream Coolify (Stripe integration, subscription gating, sponsor/upsell UI) to produce a clean, no-frills, self-hosted-only platform. See [`todo.md`](todo.md) for what's been removed and what's still tracked.

**Full-stack engineering depth**  
This project demonstrates hands-on experience across:

- Frontend modernization (Livewire → Inertia.js/React)
- Backend refactoring (Laravel controllers, policies, validation)
- Containerized development environments (Docker Compose, multiple coordinated services)
- Test-driven verification (Pest 4 feature tests written alongside every converted page)
- Documentation and architectural communication as a first-class deliverable, not an afterthought

---

## 🖥 Development Environment (Linux via WSL2)

This project is developed on **Windows 11 using WSL2 (Ubuntu)** — not native Windows.

All PHP, Node, Composer, Docker, and Laravel processes run inside the Linux subsystem to ensure production-accurate behavior:

- Matches real Linux servers (PHP-FPM, Nginx, Redis, PostgreSQL)
- Avoids Windows filesystem performance issues and slow bind mounts
- Ensures Docker behaves like production (WSL2 backend)
- Keeps Laravel’s file watchers, Vite HMR, and queue workers responsive
- Prevents Windows-specific PHP extension and path inconsistencies

The repository **must** be cloned into the WSL filesystem (e.g. `~/projects/coolify-full`), not under `C:\...`, to avoid 5–10× slower I/O and degraded Docker/Vite performance. See `DEVELOPING_IN_CONTAINERS_WINDOWS.md` and `docs/command.md`’s “WSL2 migration” section for details.

---

## 🧩 What Coolify Is (Summary)

Coolify is an open-source, self-hostable PaaS — an alternative to Heroku/Netlify/Vercel that manages servers, applications, databases, and services over SSH. This fork focuses on frontend modernization and de-commercialization rather than replicating the full upstream feature set.

---

## 🏗 Architecture Overview

This is a **single Laravel application**, not a decoupled frontend/backend split. There is no standalone React app and no separate API server — React pages render through the same Laravel routes as everything else, via Inertia.js.

```text
┌───────────────────────────────────────────────┐
│                 Laravel app                   │  (nginx + PHP-FPM, one container)
│   Inertia/React pages (majority) + remaining  │  ← page-by-page migration,
│   Livewire/Blade pages — same routes/auth     │     nearly complete
│   Horizon queue workers (deploys, backups)    │
└──────┬──────────┬─────────────┬───────────────┘
       ▼          ▼             ▼
   Postgres    Redis      coolify-realtime
  (database) (cache +    (Soketi WebSockets for live
              queues)     status + Node terminal-server
                          for SSH terminals)

  Dev-only:  Vite (HMR, never browsed directly) · Mailpit (mail capture)
             MinIO (S3 for backup tests) · testing-host (fake managed server)
