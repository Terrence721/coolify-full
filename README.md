# 🚀 Coolify-Full (Enhanced Fork) — Senior Full-Stack Engineering Demonstration

**Last Updated: July 10, 2026**

This repository is a professionally enhanced fork of [Coolify](https://coolify.io), created to demonstrate senior full-stack engineering capabilities across frontend modernization, backend engineering, and containerized infrastructure.

It showcases real-world engineering work including:

- Migrating a legacy Laravel Livewire UI to Inertia.js + React, page by page, with every phase documented and verified
- Removing the commercial/billing surface area to produce a clean, self-hosted-only fork
- Working inside — and being honest about the constraints of — a large, real-world Laravel monolith rather than a greenfield rewrite

This project is not affiliated with the Coolify team and is intended solely as a technical portfolio artifact.

## 🧭 Why This Project Matters

Rewriting a UI from scratch is easy when there's no existing app to keep working. This project demonstrates the harder, more common real-world task: modernizing a live, actively-used Laravel application's frontend **without a big-bang rewrite** — converting one page at a time, verifying each conversion automatically, and keeping a running audit trail a reviewer can actually check.

**Incremental modernization, not a rewrite**
The original Coolify UI is built on Blade, Livewire, and Alpine.js. Rather than discarding that and building a separate SPA, this fork adopts **Inertia.js**: converted pages become React components rendered through the same Laravel routes, while unconverted pages stay on Livewire. Both stacks coexist in the same app throughout the migration — see [`docs/livewire-to-react-migration.md`](docs/livewire-to-react-migration.md) for the full, phase-by-phase log (page inventory, conversion recipes, what was verified and how).

**Why Inertia over a decoupled SPA + API**
A plain React SPA would require designing and versioning a whole new API surface before a single page could move. Inertia avoids that: each migrated page stays a normal Laravel route/controller returning props, so migrated and not-yet-migrated pages coexist under the same app, and Laravel's existing routing, auth, CSRF, and session handling keep working unchanged.

**De-commercialization**
This fork also strips the SaaS/billing surface area from upstream Coolify (Stripe integration, subscription gating, sponsor/upsell UI) to produce a clean, no-frills, self-hosted-only platform. See [`TODO.md`](TODO.md) for what's been removed and what's still tracked.

**Full-stack engineering depth**
This project demonstrates hands-on experience across:

- Frontend modernization (Livewire → Inertia.js/React)
- Backend refactoring (Laravel controllers, policies, validation)
- Containerized development environments (Docker Compose, multiple coordinated services)
- Test-driven verification (Pest 4 feature tests written alongside every converted page)
- Documentation and architectural communication as a first-class deliverable, not an afterthought

## 🧩 What Coolify Is (Summary)

Coolify is an open-source, self-hostable PaaS — an alternative to Heroku/Netlify/Vercel that manages servers, applications, databases, and services over SSH. This fork focuses on frontend modernization and de-commercialization rather than replicating the full upstream feature set.

## 🏗 Architecture Overview

This is a **single Laravel application**, not a decoupled frontend/backend split. There is no standalone React app and no separate API server — React pages render through the same Laravel routes as everything else, via Inertia.js.

```text
┌─────────────────────────────────────────┐
│              Laravel app                 │  (nginx + PHP-FPM, one container)
│  Livewire/Blade pages  +  Inertia/React  │  ← coexist, page-by-page migration
│  pages, same routes, same auth/session   │
└──────────────┬────────────────────────────┘
               │
       ┌───────┼────────┬─────────────┐
       ▼       ▼        ▼             ▼
   Postgres  Redis   Soketi      Vite dev server
  (database) (cache/  (WebSockets  (asset bundling +
             queues)  / real-time) HMR only — not
                                   browsed directly)
```

Vite's dev server (default port `5173`) exists only to compile and hot-reload JS/CSS assets — you never open it in a browser. The app itself, Livewire pages and Inertia/React pages alike, is served entirely through the Laravel container.

## ▶️ Running the Project

**Prerequisites**

- Docker
- Docker Compose

**Start the dev stack**

```bash
spin up
# or, equivalently:
docker compose -f docker-compose.dev.yml up -d
```

**Access the application**

- App (Livewire + Inertia/React pages, same origin): [http://localhost:8000](http://localhost:8000)
- Vite dev server (asset bundling/HMR, not for browsing): `http://localhost:5173`

## 📚 Frontend Migration in Progress

The Livewire → Inertia.js/React migration is being done page-by-page and tracked as a living, audited process:

- [`docs/livewire-to-react-migration.md`](docs/livewire-to-react-migration.md) — full migration log: page inventory, triage (Easy/Medium/Hard buckets), conversion recipes, and per-phase verification results.
- [`docs/smoketest.md`](docs/smoketest.md) — manual QA checklist for behavior automated checks can't fully exercise (real-time/SSH-dependent pages).
- [`docs/architecture.md`](docs/architecture.md) — verified repository structure, backend/frontend layout, and Docker setup.
- [`TECH_STACK.md`](TECH_STACK.md) — current technology stack, including the coexistence of Livewire/Alpine and Inertia.js/React.
- [`TODO.md`](TODO.md) — living list of what's done and what's left, including the de-commercialization work.

## 🧰 Tech Stack

- Laravel 12 (PHP 8.4/8.5)
- Livewire 3 + Alpine.js (legacy pages, being migrated off)
- Inertia.js + React 19 (converted pages)
- Tailwind CSS v4
- PostgreSQL
- Redis (cache, queues)
- Soketi (WebSocket server for real-time features)
- Docker & Docker Compose
- Pest 4 (feature/unit testing), PHPStan/Larastan (static analysis), Laravel Pint (code style)

## 📄 Disclaimer

This repository is a modified fork of Coolify and is intended solely for educational and portfolio purposes. All original Coolify branding, trademarks, and documentation belong to their respective owners.

## 🎯 Why This Project Demonstrates Senior Full-Stack Ability

This fork highlights:

- Modernizing a legacy system incrementally, without breaking it mid-migration
- Full-stack proficiency across Laravel, Livewire, and React/Inertia
- Judgment about **when not** to introduce new architecture (e.g., reusing existing shared components instead of duplicating logic, documenting deliberately-skipped verification steps rather than silently omitting them)
- Test-driven verification and an honest, audited paper trail — including documented gaps, not just successes
- Clear technical documentation as a deliverable in its own right
