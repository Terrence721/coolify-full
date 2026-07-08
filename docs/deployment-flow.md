Deployment Flow — Laravel → Agents → Remote Servers
This document explains how deployments work inside Coolify‑Full.

🚀 High‑Level Deployment Flow

React 19 UI
   ↓
Laravel Controller
   ↓
Deployment Service
   ↓
Queue Job
   ↓
Coolify Agent
   ↓
Remote Server
   ↓
Status Report → Laravel → React UI

🧭 Step‑by‑Step
1. User triggers deployment
Via React 19 UI.

2. Laravel creates a Deployment Job
Stored in database.

3. Job dispatched to queue
Handled by Laravel’s queue workers.

4. Coolify Agent receives job
Agent runs on remote server.

5. Agent executes commands
Using templates from:
templates/

6. Agent reports status
Back to Laravel.

7. React UI updates
Via Inertia.js.

🧩 Key Components
app/Services/DeploymentService.php

agents/

templates/

scripts/

routes/api.php

resources/js/Pages/Deployments/*
