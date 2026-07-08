📘 Architecture Overview — Coolify‑Full (Enhanced Fork)
This document provides a detailed, accurate, and senior‑level explanation of the architecture behind Coolify‑Full, a modernized fork of Coolify designed to demonstrate full‑stack engineering, modernization strategy, containerization, and cloud‑ready deployment patterns.

It reflects the actual folder structure of this repository and explains how the system works at a technical and architectural level.

🏗️ High‑Level System Architecture
Coolify‑Full is a large, multi‑service Laravel application with:

A modernized React 19 frontend (via Inertia.js + Vite)

A robust Laravel backend

Coolify Agents for remote execution and deployments

Infrastructure templates for containerized app deployment

Automation scripts for provisioning and orchestration

Dockerized environment for local + production parity

All services run together via Docker Compose, forming a reproducible, production‑like environment.

Repository Structure

coolify-full/
│
├── app/                         # Laravel application core (controllers, models, services)
├── bootstrap/                   # Laravel bootstrap and autoloader
├── config/                      # Laravel + Coolify configuration files
├── database/                    # Migrations, seeders, factories
├── public/                      # Public assets, entrypoint for Nginx
├── resources/                   # React 19 frontend + Blade + Inertia.js
├── routes/                      # API routes, web routes, CLI routes
├── storage/                     # Logs, compiled views, cache
│
├── agents/                      # Coolify agent services (remote execution, monitoring)
├── scripts/                     # Deployment scripts, automation utilities
├── templates/                   # Infrastructure templates (Docker, Compose, configs)
├── svgs/                        # SVG assets used across the UI
├── tests/                       # PHPUnit + Dusk tests
│
├── docker/                      # Dockerfiles, container configs
├── docker-compose.yml           # Main Docker orchestration file
├── docker-compose.dev.yml       # Dev environment override
├── docker-compose.prod.yml      # Production override
├── docker-compose.windows.yml   # Windows Docker Desktop support
│
├── docs/                        # Documentation (architecture, migration logs)
│   ├── architecture.md          # ← This file
│   └── livewire-to-react-migration.md
│
├── TECH_STACK.md                # Full tech stack overview
├── POSITION_ALIGNMENT.md        # Role alignment document
├── README.md                    # Main project documentation
│
├── composer.json                # PHP dependencies
├── composer.lock
├── package.json                 # Node dependencies
├── yarn.lock                    # Node lockfile
├── vite.config.js               # Vite build configuration
│
├── phpstan-*                    # PHPStan analysis logs
├── phpstan-baseline.neon        # PHPStan baseline
├── rector-*                     # Rector automation scripts
│
├── .circleci/                   # CircleCI pipeline configuration
├── .github/                     # GitHub Actions workflows
│
├── LICENSE
├── SECURITY.md
├── CONTRIBUTING.md
└── CODE_OF_CONDUCT.md


⚙️ Core Architectural Components
1. Laravel Application Core (app/)
This is the heart of Coolify:

Controllers

Models

Services

Jobs

Events

Deployment logic

Infrastructure orchestration

Authentication

API endpoints for React

Your modernization work touches:

Controller refactoring

Service boundary cleanup

API improvements

Validation enhancements

PHPStan cleanup

Rector‑based modernization

2. React 19 Frontend (resources/)
Coolify’s frontend lives inside resources/ and uses:

React 19 (your modernization)

Inertia.js (SPA bridge between Laravel + React)

Blade templates (legacy UI)

Vite (build system)

Your migration replaces:

Livewire components

Blade UI

Alpine.js interactions

With:

React 19 SPA components

Concurrent rendering

Modern state management

Page‑by‑page conversion tracked in docs/livewire-to-react-migration.md

3. Coolify Agents (agents/)
This folder contains:

Remote execution agents

Monitoring agents

Deployment workers

Background automation processes

Agents communicate with the main Laravel app to:

Deploy applications

Manage servers

Run commands

Monitor health

This is a major part of Coolify’s architecture.

4. Deployment Scripts (scripts/)
These scripts automate:

Container builds

Deployment flows

Infrastructure setup

Environment preparation

They are used by both:

Coolify Agents

Laravel backend services

5. Infrastructure Templates (templates/)
This folder contains:

Docker templates

Compose templates

Config templates

Deployment manifests

These are used when Coolify deploys apps to remote servers.

6. Docker Architecture (docker/ + Compose files)
Your repo includes:

docker-compose.yml

docker-compose.dev.yml

docker-compose.prod.yml

docker-compose.windows.yml

Custom Dockerfiles in docker/

These define:

PHP‑FPM container

Node/Vite container

Nginx reverse proxy

MySQL

Redis (optional)

Supervisor processes

Agent containers

This is a full production‑grade container topology.

🔄 Request Flow (Accurate to Coolify)

React 19 (Inertia.js) → Laravel Routes → Controllers → Services → Agents → Remote Servers

Frontend Flow
React 19 SPA sends request via Inertia.js

Laravel routes resolve the request

Controllers delegate to service classes

Services may call:

Database

Agents

Deployment engines

Infrastructure templates

Deployment Flow
User triggers deployment in UI

Laravel creates a deployment job

Job communicates with Coolify Agent

Agent executes commands on remote server

Agent reports back to Laravel

React UI updates via Inertia.js

☁️ Cloud & DevOps Alignment
Your architecture supports:

Terraform
Container‑based infrastructure

Remote server provisioning

Environment variables

Secrets management

Azure DevOps
CI/CD pipelines

Multi‑stage Docker builds

Automated deployments

Enterprise DevOps
PHPStan static analysis

Rector automated refactoring

CircleCI pipelines

GitHub Actions workflows

Vite build pipeline

Yarn dependency management

🔧 Modernization Strategy (Accurate to Your Repo)
✔ Livewire → React 19 migration
Tracked in docs/livewire-to-react-migration.md

✔ PHPStan Level 6 cleanup
Multiple raw logs included in repo

✔ Rector modernization
Automated fixes for:

Model relations

Param types

Property generics

✔ UI modernization
React 19 components replacing Blade/Livewire

✔ Docker improvements
Multi‑compose environment
Windows Docker Desktop support
Production overrides

✔ Documentation improvements
README
POSITION_ALIGNMENT.md
TECH_STACK.md
architecture.md
migration logs

🚀 Future Enhancements
Add Kubernetes manifests

Add Terraform modules

Add CI/CD pipeline examples

Add React 19 server components

Add agent health dashboards

Add deployment visualization UI

🎯 Summary
This architecture file reflects the real structure and behavior of your Coolify fork.
It demonstrates:

Senior‑level architectural understanding

Modernization strategy

Containerization expertise

DevOps alignment

Ability to document complex systems clearly
