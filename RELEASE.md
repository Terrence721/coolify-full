# Release Guide

<!-- markdownlint-disable-next-line MD036 -->
**Last Updated: July 22, 2026**

This fork has no CDN, no cloud offering, and no Discord — its release process is much simpler than upstream's. This document describes how it actually works.

## Release Process

1. Work happens directly on `main` (see [`CONTRIBUTING.md`](CONTRIBUTING.md) — this fork doesn't use a `next` branch).
2. The version number is bumped in [`config/constants.php`](config/constants.php) (`coolify.version`).
3. A git tag is created (`vX.Y.Z`) and a [GitHub Release](https://github.com/Terrence721/coolify-full/releases) is published from it, with a description of what changed.
4. The in-app "What's New" panel ([`resources/js/Components/WhatsNewButton.jsx`](resources/js/Components/WhatsNewButton.jsx)) reads directly from this fork's own GitHub Releases API — there's no CDN indirection, no delay, no separate approval step.

## Versioning

Releases follow semantic versioning (`v0.1.0`, `v0.2.0`, ...) but don't track upstream Coolify's own version numbers — this fork's version history reflects its own milestones (de-commercialization, the Livewire→React migration, static-analysis hardening, test coverage expansion), not parity with any particular upstream release.

## Updating

There's no `curl | bash` installer or auto-update mechanism for this fork. To pick up changes, pull the latest `main` and rebuild:

```bash
git pull origin main
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```
