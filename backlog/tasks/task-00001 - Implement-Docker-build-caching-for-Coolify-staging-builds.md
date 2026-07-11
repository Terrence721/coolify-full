---
id: task-00001
title: Implement Docker build caching for Coolify staging builds
status: Done
assignee: []
created_date: '2025-08-26 12:15'
updated_date: '2026-07-12 01:00'
labels:
  - heyandras
  - performance
  - docker
  - ci-cd
  - build-optimization
dependencies: []
priority: high
---

## Description

Implement comprehensive Docker build caching to reduce staging build times by 50-70% through BuildKit cache mounts for dependencies and GitHub Actions registry caching. This optimization will significantly reduce build times from ~10-15 minutes to ~3-5 minutes, decrease network usage, and lower GitHub Actions costs.

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Docker BuildKit cache mounts are added to Composer dependency installation in production Dockerfile
- [x] #2 Docker BuildKit cache mounts are added to NPM dependency installation in production Dockerfile
<!-- AC:END -->

## Implementation Notes (2026-07-12)

Re-scoped to this fork. The original ACs #3–#7 (GitHub Actions BuildX setup, registry cache-from/cache-to, build-time reduction target, GitHub Actions minutes reduction, no-regressions check) all depended on `.github/workflows/coolify-staging-build.yml`, upstream Coolify's cloud staging-build pipeline — this fork doesn't have that workflow at all (its only workflow is `.github/workflows/quality.yml`), so there's nothing there to configure or measure. Removed those criteria and their 5 subtasks (00001.02 through 00001.06) rather than leave permanently-inapplicable open items in the tracker. What's actually applicable to this fork — BuildKit cache mounts in `docker/production/Dockerfile` — is done: `composer install` is wrapped in `--mount=type=cache,target=/tmp/cache`, and `yarn install` (this fork uses Yarn, not NPM) is wrapped in `--mount=type=cache,target=/usr/local/share/.cache/yarn`.

## Implementation Plan

1. Modify docker/production/Dockerfile to add BuildKit cache mounts:
   - Add cache mount for Composer dependencies: --mount=type=cache,target=/tmp/cache
   - Add cache mount for Yarn dependencies: --mount=type=cache,target=/usr/local/share/.cache/yarn
