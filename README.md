**[→ Read the one-page portfolio](https://terrence721.github.io/coolify-full/portfolio.html)** — the 60-second version, with links back into this repo for anyone who wants to go deeper.

# 🚀 Coolify-Full (Enhanced Fork) — Senior Full-Stack Engineering Demonstration

[![Quality](https://github.com/Terrence721/coolify-full/actions/workflows/quality.yml/badge.svg)](https://github.com/Terrence721/coolify-full/actions/workflows/quality.yml)
[![CodeQL](https://github.com/Terrence721/coolify-full/actions/workflows/codeql.yml/badge.svg)](https://github.com/Terrence721/coolify-full/actions/workflows/codeql.yml)

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: August 17, 2026**

This repository is a professionally enhanced fork of Coolify, created to demonstrate senior full-stack engineering capabilities across frontend modernization, backend engineering, and containerized infrastructure.

### 🧭 Start Here

- **[Architecture Overview](https://terrence721.github.io/coolify-full/diagrams/system-architecture.html)** — subsystems, core principles, system diagram
- **[Modernization Roadmap](https://terrence721.github.io/coolify-full/diagrams/livewire-to-inertia-request-flow.html)** — the Livewire → React 19 migration story and request-flow diagram
- **[Deployment Pipeline](https://terrence721.github.io/coolify-full/diagrams/deployment-pipeline.html)** — the full 7-step provisioning flow, key design decisions (e.g. the shared-CA-per-server / leaf-cert-per-database TLS model), Docker Compose generation, and the per-engine `StartAction` pattern
- **[Testing Strategy](https://terrence721.github.io/coolify-full/diagrams/testing-strategy-layers.html)** — the 3-layer Pest/Vitest/browser-smoke-test split, with real examples of bugs only one layer caught

The rest of the [wiki](https://github.com/Terrence721/coolify-full/wiki) goes deeper per-subsystem (Environment Variables, Persistent Volumes, and one page per database engine's provisioning action).

![Dashboard showing two projects (E-Commerce Platform, Internal Tools) and a managed Docker server](docs/screenshots/dashboard.png)

It showcases real-world engineering work including:

- Migrating a legacy Laravel Livewire UI to Inertia.js + React, page by page — **complete** as of 2026-07-14, every phase documented and verified
- A sustained static-analysis hardening pass: PHPStan's suppressed-error baseline taken from 1,306 down to 55 across 65 phases, each verified with a full test-suite run — the remaining 55 are individually confirmed analysis-tool limitations (documented in [`todo.md`](todo.md#phpstan-baseline-reductions)), not unaddressed work
- Security-specific static analysis beyond type-safety — CodeQL for the React frontend, Psalm taint analysis for the PHP backend, added as part of this hardening pass, catching 11 real CVE advisories and 2 real findings in the process (see [`todo.md`](todo.md))
- Removing the commercial/billing surface area to produce a clean, self-hosted-only fork
- Working inside — and being honest about the constraints of — a large, real-world Laravel monolith rather than a greenfield rewrite
- Linux-native engineering throughout: every process (PHP, Node, Docker, Postgres, Redis) runs in **Ubuntu Linux** — the Windows machine is only the host (WSL2)

This project is not affiliated with the Coolify team and is intended solely as a technical portfolio artifact.

**At a glance:** 84/84 Livewire pages converted to React · PHPStan baseline 1,306 → 55 (65 phases) · 1,475 Pest tests passing (5,725 assertions) · 1,418 Vitest/React Testing Library component tests (145 test suites) · zero known regressions — every number here is reproducible from this repo's own commit history, not a claim to take on faith.

**Reading the commit history:** 992 commits total — 65 are PHPStan hardening phases (one of them, `33b`, papers over a CI-only bug Phase 33 left behind, hence the letter suffix), 339 touch only documentation/tracking files ([`todo.md`](todo.md), [`README.md`](README.md), [`docs/*.md`](docs), [`ROADMAP.md`](ROADMAP.md)), 89 are merge commits for real GitHub Pull Requests (most are code-review findings, tracked in [`docs/code-review.md`](docs/code-review.md); a smaller, growing set are routine dependency security-patch merges instead — e.g. `undici`, `postcss`, `fast-uri`, `guzzlehttp/guzzle`, `brace-expansion` — not itemized there since there's no finding to write up, just a version bump; 14 further merge commits aren't PRs at all — resolving remote/local doc conflicts, and bringing still-open PR branches up to date with `main` mid-review), and the remaining 485 are other engineering work (features, bug fixes, the React migration). `git log --oneline | grep -E "^[a-f0-9]+ Phase [0-9]+[a-z]? —"` isolates just the PHPStan phases if you want to skip straight to that thread — note the trailing `[a-z]?`, without it you'll silently miss Phase 33b (plain `git log --grep=`, unlike this, also matches "Phase N" mentions inside unrelated commit bodies — worth knowing if you go digging further yourself). [`todo.md`'s "PHPStan baseline reductions" section](todo.md#phpstan-baseline-reductions) has a per-phase summary table (baseline delta, focus, highlight) plus a "PHPStan baseline milestones" table for the phases that found a real bug or a structural fix, including one phase (59) that landed folded into an emergency CI-fix commit ([`3894266f4`](https://github.com/Terrence721/coolify-full/commit/3894266f41d8e82ae42ab51306966b12d9e7601a)) rather than its own "Phase 59 —" commit.

---

## 🧭 Why This Project Matters

Rewriting a UI from scratch is easy when there's no existing app to keep working. This project demonstrates the harder, more common real-world task: modernizing a live, actively-used Laravel application's frontend **without a big-bang rewrite** — converting one page at a time, verifying each conversion automatically, and keeping a running audit trail a reviewer can actually check.

**Incremental modernization, not a rewrite**  
The original Coolify UI was built on Blade, Livewire, and Alpine.js. Rather than discarding that and building a separate SPA, this fork adopted **Inertia.js**: pages became React components rendered through the same Laravel routes, migrated incrementally rather than in one big-bang rewrite. As of 2026-07-14 the migration is complete — every full-page route and all navigation/chrome infrastructure is React, and `livewire/livewire`/Alpine.js have both been removed from the app entirely. See [`docs/livewire-to-react-migration.md`](docs/livewire-to-react-migration.md) for the full, phase-by-phase log (page inventory, conversion recipes, what was verified and how), or the **[request-flow diagram](https://terrence721.github.io/coolify-full/diagrams/livewire-to-inertia-request-flow.html)** for the short visual version.

**Why Inertia over a decoupled SPA + API**  
A plain React SPA would require designing and versioning a whole new API surface before a single page could move. Inertia avoids that: each migrated page stays a normal Laravel route/controller returning props, so migrated and not-yet-migrated pages coexist under the same app, and Laravel's existing routing, auth, CSRF, and session handling keep working unchanged. Navigation still makes real AJAX (XHR) requests — that part doesn't go away — but the response is JSON props for the same controller/route, not a separately-versioned API or re-rendered HTML. See [`docs/livewire-to-react-migration.md`](docs/livewire-to-react-migration.md) ("Why Inertia.js instead of a plain React SPA + REST API") for the mechanism in full.

**De-commercialization**  
This fork also strips the SaaS/billing surface area from upstream Coolify (Stripe integration, subscription gating, sponsor/upsell UI) to produce a clean, no-frills, self-hosted-only platform. See [`todo.md`](todo.md) for what's been removed and what's still tracked.

**Full-stack engineering depth**  
This project demonstrates hands-on experience across:

- Frontend modernization (Livewire → Inertia.js/React, now complete)
- Backend refactoring (Laravel controllers, policies, validation)
- Containerized development environments (Docker Compose, multiple coordinated services)
- Test-driven verification: Pest 4 feature tests written alongside every converted page (backend), Vitest + React Testing Library for frontend component behavior (see [`todo.md`'s "Frontend component testing" section](todo.md#frontend-component-testing))
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

The repository **must** be cloned into the WSL filesystem (e.g. `~/projects/coolify-full`), not under `C:\...`, to avoid 5–10× slower I/O and degraded Docker/Vite performance. See [`DEVELOPING_IN_CONTAINERS_WINDOWS.md`](DEVELOPING_IN_CONTAINERS_WINDOWS.md) and [`docs/command.md`](docs/command.md) for details (its "WSL2 migration" section covers the full root-cause story).

**Reviewing from native Linux (or macOS)?** Nothing in this repo is Windows-specific. The entire toolchain — bash, Docker Compose, Composer, Artisan, Vite — is Linux-native and runs identically on any Linux machine: clone, `cp .env.development.example .env`, `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d`, done. The WSL2 notes exist only because the host hardware happens to run Windows; the development experience is Ubuntu all the way down.

---

## 🧩 What Coolify Is (Summary)

Coolify is an open-source, self-hostable PaaS — an alternative to Heroku/Netlify/Vercel that manages servers, applications, databases, and services over SSH. This fork strips that down to a no-frills self-hosted tool: the entire Stripe/subscription billing subsystem is gone (no `subscriptions` table, no payment-gated features, no sponsor/upsell UI, no server-count caps tied to a paid tier), leaving the deployment/server-management core with nothing else to configure or pay for.

---

## 🏗 Architecture Overview

This is a **single Laravel application**, not a decoupled frontend/backend split. There is no standalone React app and no separate API server — React pages render through the same Laravel routes as everything else, via Inertia.js.

```text
┌───────────────────────────────────────────────┐
│                 Laravel app                   │  (nginx + PHP-FPM, one container)
│   Inertia/React pages (92 .jsx pages) — all   │  ← migration complete,
│   full-page routes, same Laravel routes/auth  │     no Livewire remains
│   Horizon queue workers (deploys, backups)    │
└──────┬──────────┬─────────────┬───────────────┘
       ▼          ▼             ▼
   Postgres    Redis      coolify-realtime
  (database) (cache +    (Soketi WebSockets for live
              queues)     status + Node terminal-server
                          for SSH terminals)

  Dev-only:  Vite (HMR, never browsed directly) · Mailpit (mail capture)
             MinIO (S3 for backup tests) · testing-host (fake managed server)
             autoheal (WSL2 bind-mount race recovery) · stray-pruner (orphan cleanup)
```

**Why a monolith, not microservices**  
This is a deliberate choice, evaluated directly rather than assumed. The one piece that genuinely needed to be a separate process — `coolify-realtime`, for WebSocket broadcasting and SSH terminal streaming — already is, running as its own Node service; everything else stays in the single Laravel app. The reasoning: the domain (servers, applications, deployments, proxy config) is tightly coupled through the same database, so splitting it apart would trade fast, transactional Eloquent relations for network calls and eventual consistency with no corresponding capability gained. More fundamentally, the two strongest real-world reasons to run microservices — independent scaling and independent team ownership — don't apply here: this is a self-hosted, single-instance app (one install managing N remote servers, not a horizontally-scaled multi-tenant SaaS) maintained by one person. Real upstream Coolify (coollabsio) is also a monolith, for the same reasons. The trade-off is stated plainly, not glossed over: a monolith couples deployment (every change ships the whole app together) in exchange for avoiding the operational cost of running and coordinating multiple deployables — a trade that favors the monolith here, though it's one worth revisiting if this project's shape ever changed. Full reasoning in [`docs/architecture.md`](docs/architecture.md#2-monolith-not-microservices).

---

## 📋 Project Tracking

Work on this fork is tracked two ways:

- **[`todo.md`](todo.md)** — the primary, detailed record: a phase-by-phase written log of everything done and everything still open, with dates, verified deltas, and the reasoning behind each decision. This is the source of truth.
- **[GitHub Project board](https://github.com/users/Terrence721/projects/1)** — a Scrum-style Backlog/Planned/In Progress/Verification & QA/Done view of the same work, for a quick at-a-glance status without reading the full log. Kept in sync with [`todo.md`](todo.md).
- **[`ROADMAP.md`](ROADMAP.md)** — a different kind of list: product-direction ideas found by reading the code but not yet scoped into work items. Once picked up, an idea moves out of here and into `todo.md`/the board like everything else.
- **[`docs/code-review.md`](docs/code-review.md)** — real line-level code review notes, done as a reviewer role separate from the engineer who fixes them (see `todo.md`).
