Docker Architecture — Multi‑Container Full‑Stack System
This document explains the Docker architecture inside your Coolify‑Full repository.

🧱 Overview
Your repo includes:

docker-compose.yml

docker-compose.dev.yml

docker-compose.prod.yml

docker-compose.windows.yml

docker/ folder with Dockerfiles

🐳 Containers
1. PHP‑FPM (Laravel)
Runs the backend.

2. Node/Vite (React 19)
Builds and serves the frontend.

3. Nginx
Reverse proxy for:

/api/* → Laravel

/ → React build

4. MySQL
Primary database.

5. Redis (optional)
Caching + queues.

6. Coolify Agents
Remote execution workers.

🔧 Dockerfile Structure
Located in:
docker/

Includes:

PHP‑FPM Dockerfile

Node build Dockerfile

Supervisor configs

Agent runtime configs

🧩 Compose Overrides
Development
docker-compose.dev.yml
Production
docker-compose.prod.yml
Windows
docker-compose.windows.yml
🎯 Docker Goals
Reproducible builds

Production‑like environment

Multi‑service orchestration

Cloud‑ready deployment
