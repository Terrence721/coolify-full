# Coolify Technology Stack

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 13, 2026**

## Development Environment

-   Ubuntu Linux (via WSL2 on a Windows 11 host) — every process runs in Linux; nothing in the toolchain is Windows-specific
-   Docker Compose dev stack (app, Postgres, Redis, Soketi/terminal-server, Vite, Mailpit, MinIO, testing-host), all Linux containers on a Linux filesystem
-   Identical workflow on native Linux/macOS — the WSL2 layer is host-machine detail, not a project requirement

## Frontend

-   Livewire and Alpine.js
-   Inertia.js and React (in progress — a page-by-page migration off Livewire; see [docs/livewire-to-react-migration.md](docs/livewire-to-react-migration.md))
-   Blade (PHP templating engine)
-   Tailwind CSS
-   Monaco Editor (Code editor component)
-   XTerm.js (Terminal component)

## Backend

-   Laravel 12 (PHP Framework)
-   PostgreSQL 15 (Database)
-   Redis 7 (Caching & Real-time features)
-   Soketi (WebSocket Server)

## DevOps & Infrastructure

-   Docker & Docker Compose
-   Nginx (Web Server)
-   S6 Overlay (Process Supervisor)
-   GitHub Actions (CI/CD)

## Languages

-   PHP 8.4
-   JavaScript
-   Shell/Bash scripts
