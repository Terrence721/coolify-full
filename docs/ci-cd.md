CI/CD Architecture — CircleCI + GitHub Actions + Docker
This document explains the CI/CD pipelines inside your Coolify‑Full repository.

🚀 Overview
Your repo includes:

CircleCI (.circleci/)

GitHub Actions (.github/workflows/)

Docker multi‑stage builds

PHPStan + Rector static analysis

Automated testing

📁 Actual CI/CD Structure

.circleci/
│   └── config.yml          # CircleCI pipeline

.github/
│   └── workflows/
│       ├── tests.yml       # PHPUnit + Dusk
│       ├── build.yml       # Docker builds
│       └── lint.yml        # PHPStan + Rector

🧪 Testing Pipeline
Runs:

PHPUnit

Dusk browser tests

PHPStan

🐳 Docker Build Pipeline
Uses multi‑stage builds:

Composer install

Node/Vite build

PHP‑FPM runtime

Nginx runtime

🔐 Secrets & Environment
Uses:

.env.testing

.env.development.example

.env.windows-docker-desktop.example

🎯 CI/CD Goals
Ensure modernization stability

Validate React migration

Validate Laravel services

Build production‑ready containers

Maintain PHPStan Level 6 compliance

Rector

Ensures code quality and modernization.
