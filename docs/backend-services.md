Backend Services Architecture — Laravel Application Core
This document explains the backend architecture inside your Coolify‑Full repository.

🧭 Overview
Coolify‑Full’s backend is a large Laravel application with:

Controllers

Models

Services

Jobs

Events

Deployment engines

Coolify Agents

Infrastructure orchestration

API endpoints for React

📁 Actual Backend Folder Structure

app/
│
├── Console/               # Artisan commands
├── Http/
│   ├── Controllers/       # API + web controllers
│   ├── Middleware/        # Request middleware
│   └── Requests/          # Validation
├── Models/                # Eloquent models
├── Services/              # Business logic
├── Jobs/                  # Queued jobs
├── Events/                # Domain events
├── Actions/               # Reusable action classes
└── Providers/             # Laravel service providers


⚙️ Key Backend Components

Controllers
Handle:

API endpoints

Inertia page responses

Deployment triggers

Authentication flows

Services
Contain business logic for:

Deployments

Server management

Application provisioning

Agent communication

Models
Represent:

Servers

Deployments

Applications

Users

Credentials

Infrastructure metadata

Jobs
Used for:

Async deployments

Remote execution

Background tasks

Events
Trigger:

Deployment notifications

Agent updates

System monitoring

🤖 Coolify Agents

agents/


Agents perform:

Remote command execution

Deployment tasks

Monitoring

Health checks

They communicate with Laravel via secure channels.

🧪 Testing

tests/
│
├── Feature/
├── Unit/
└── Dusk/

Includes:

PHPUnit tests

Browser tests

Deployment flow tests

🎯 Backend Modernization Goals
Clean controller/service boundaries

Improve validation

Add React‑friendly API endpoints

Reduce Blade coupling

Improve deployment reliability

Modernize PHP code using Rector + PHPStan
