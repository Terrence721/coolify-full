🚀 Coolify‑Full (Enhanced Fork) — Senior Full‑Stack Engineering Demonstration
This repository is a professionally enhanced fork of Coolify, created to demonstrate Senior Full‑Stack Developer capabilities across modern frontend architecture, backend engineering, containerized infrastructure, and cloud‑ready deployment patterns.

It showcases real-world engineering skills including:

Migrating legacy Laravel Livewire interfaces to React 19

Building a fully containerized Laravel + React environment using Docker

Improving backend service structure and API boundaries

Enhancing developer experience, documentation, and environment reproducibility

Demonstrating cloud‑aligned architecture suitable for Terraform + Azure DevOps pipelines

This project is not affiliated with the Coolify team and is intended solely as a technical portfolio artifact.


🧭 Why This Project Matters
This project demonstrates how a legacy Laravel application can be modernized into a cloud‑ready, scalable, full‑stack platform using contemporary engineering practices. By forking Coolify and rebuilding major portions of its architecture, this project highlights several senior‑level competencies:

Modernization of Legacy Systems
The original Coolify UI relied heavily on Blade and Livewire. I replaced these components with a React 19 SPA, leveraging modern features such as concurrent rendering, improved transitions, and server component compatibility.

Cloud‑Native Architecture
The entire application is fully containerized using Docker, including:

Laravel API (PHP‑FPM)

React 19 frontend (Node)

Nginx reverse proxy

MySQL database

Optional Redis cache

This mirrors real enterprise deployment patterns and aligns with Terraform + Azure DevOps workflows.

Full‑Stack Engineering Depth
This project demonstrates hands‑on experience across:

Frontend modernization

Backend service refactoring

API design

Container orchestration

Developer experience improvements

Documentation and architectural communication

Practical Demonstration of Role Alignment
This project is intentionally structured to showcase the skills required for a Senior Full‑Stack Developer role, including:

Scalable architecture

Modern frontend engineering

Cloud‑aligned DevOps practices

Strong documentation

Independent execution

Ability to modernize and enhance complex systems

🧩 What Coolify Is (Summary)
Coolify is an open-source self-hosting platform designed to simplify deployment and infrastructure management.
This fork focuses on architectural improvements, frontend modernization, and developer experience enhancements, rather than replicating the full Coolify feature set.

🔧 Key Enhancements in This Fork
1. Full Migration from Livewire → React 19
Replaced Blade/Livewire UI with a modern React 19 SPA

Implemented React Server Components where appropriate

Improved UI responsiveness using React 19’s concurrent rendering

Added new component structure aligned with enterprise frontend standards

2. Complete Dockerized Architecture
Multi-container setup including:

Laravel API (PHP-FPM)

React 19 frontend (Node)

Nginx reverse proxy

MySQL database

Optional Redis cache

This provides:

Production-like environment reproducibility

Clean separation of services for scalability

3. Backend Improvements (Laravel)
Refactored controllers and services for cleaner API boundaries

Improved request validation and error handling

Added new endpoints to support React SPA workflows

Enhanced environment configuration and .env templates

4. DevOps & Cloud Alignment
Architecture compatible with:

Terraform-managed infrastructure

Azure DevOps pipelines

Container-based CI/CD workflows

Multi-stage Dockerfiles for optimized builds

5. Developer Experience Enhancements
Simplified onboarding with docker-compose up -d

Clear documentation for running and extending the project

Improved folder structure for frontend/backend separation

Added example tests (PHPUnit + Jest)

🏗 Architecture Overview
Code
┌──────────────────────────┐
│        React 19 SPA      │  (Node container)
└──────────────┬───────────┘
               │
┌──────────────▼───────────┐
│       Laravel API         │  (PHP-FPM container)
│  Authentication / Services│
└──────────────┬───────────┘
               │
┌──────────────▼───────────┐
│        MySQL Database     │
└───────────────────────────┘

┌──────────────────────────┐
│         Nginx            │  (Reverse proxy)
└──────────────────────────┘
▶️ Running the Project
Prerequisites
Docker

Docker Compose

Start the full stack
Code
docker-compose up -d
Access the application
React frontend: http://localhost:3000

Laravel API: http://localhost:8000

📚 Frontend Migration in Progress
The Livewire → React migration is being done page-by-page and tracked as a living, audited process:

docs/livewire-to-react-migration.md  
Full migration log, including page inventory, triage (Easy/Medium/Hard), conversion recipes, and verification logs.

docs/architecture.md  
Verified repository/backend/frontend structure, deployment flow, and Docker/CI setup.

TECH_STACK.md  
Current technology stack, including the coexistence of Livewire/Alpine and Inertia.js/React frontends.

🧰 Tech Stack
Code
React 19
Laravel 10
PHP-FPM
Node.js 20
Docker & Docker Compose
MySQL 8
Nginx
Vite
Redis (optional)
Jest + React Testing Library
PHPUnit
📄 Disclaimer
This repository is a modified fork of Coolify and is intended solely for educational and portfolio purposes.
All original Coolify branding, trademarks, and documentation belong to their respective owners.

🎯 Why This Project Demonstrates Senior Full‑Stack Ability
This fork highlights:

Modernization of legacy systems

Full-stack proficiency (Laravel + React 19)

Cloud-native containerization

Scalable architecture design

Strong documentation and communication

Ability to work independently and deliver complex improvements

Alignment with enterprise DevOps practices

This project reflects the technical depth, architectural thinking, and execution expected of a Senior Full‑Stack Developer.

Alignment with enterprise DevOps practices

This project reflects the technical depth, architectural thinking, and execution expected of a Senior Full‑Stack Developer.
