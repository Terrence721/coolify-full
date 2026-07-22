# Contributing

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 22, 2026**

This repository is a personal portfolio fork (see [README.md](README.md)) and is not affiliated with the Coolify team. It's not seeking outside contributions, but the process below reflects how this fork is actually developed — no Discord, no upstream PR process, no `next`/`v4.x` branching.

## Workflow

This fork works directly on a single `main` branch. Changes are committed (and, once verified, pushed) straight to `main` — there's no fork-and-PR cycle for a single-maintainer project.

## Development Environment

See [`CLAUDE.md`](CLAUDE.md) for the full command reference and [`TECH_STACK.md`](TECH_STACK.md) for the stack overview. Short version:

```bash
spin up      # or: docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

The app runs at `localhost:8000`. On Windows, see [`DEVELOPING_IN_CONTAINERS_WINDOWS.md`](DEVELOPING_IN_CONTAINERS_WINDOWS.md) — the repo needs to live inside a WSL2 distro's native filesystem, not a Windows path, for reasonable performance.

## Code Quality

Every change is expected to pass, before being committed:

```bash
php artisan test --compact           # Pest test suite
vendor/bin/pint --dirty --format agent   # code style
composer phpstan                     # static analysis (Larastan, level 6)
yarn test                            # React component tests (Vitest)
yarn lint                            # ESLint
```

Bug fixes follow TDD: a failing test first, then the fix. See [`todo.md`](todo.md) for the running log of what's been done and why.
