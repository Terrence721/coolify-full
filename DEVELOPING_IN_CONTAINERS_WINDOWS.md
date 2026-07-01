# Developing Coolify In Containers (Windows)

This guide is for contributors who have never developed inside containers before.

It uses the Docker Compose workflow that is already working in this repository.

## 0. 5-Command Quick Start (Daily)

Run these from the project root in PowerShell:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps
Invoke-WebRequest -Uri http://localhost:8000/api/health -UseBasicParsing
docker exec coolify sh -lc "cd /var/www/html && php artisan about"
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Feature"
```

Open after startup:

- App: `http://localhost:8000`
- Vite: `http://localhost:5173`

## 1. What Is Running Where?

- Your code stays on Windows in this folder: `C:\Users\Terre\source\repos\coolify-full`
- Docker runs Linux containers for the app and services.
- The main app container name is `coolify`.
- You edit files locally in VS Code, and containers see the same files through mounted volumes.

## 2. One-Time Setup

1. Install Docker Desktop.
2. Start Docker Desktop and wait until it says Docker is running.
3. Open PowerShell in the project root.
4. Create `.env` from the development template if needed:

```powershell
Copy-Item .env.development.example .env
```

## 3. Start The Development Stack

Run from project root:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

Check status:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps
```

Expected key services:

- `coolify` (app)
- `coolify-db` (Postgres)
- `coolify-redis` (Redis)
- `coolify-realtime`
- `coolify-vite`

## 4. Open The App And Tools

- App: `http://localhost:8000`
- Vite dev server: `http://localhost:5173`
- Mailpit: `http://localhost:8025`
- Horizon: `http://localhost:8000/horizon`

If `curl` in PowerShell behaves oddly, use:

```powershell
Invoke-WebRequest -Uri http://localhost:8000/api/health -UseBasicParsing
```

## 5. Daily Workflow

1. Start Docker Desktop.
2. Start stack with `docker compose ... up -d`.
3. Edit code in VS Code.
4. Run commands inside the app container.
5. Run tests.
6. Format changed PHP files.

## 6. Run Commands Inside The Container

Use this pattern:

```powershell
docker exec coolify sh -lc "cd /var/www/html && <command>"
```

Examples:

```powershell
docker exec coolify sh -lc "cd /var/www/html && php artisan about"
docker exec coolify sh -lc "cd /var/www/html && php artisan migrate"
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact"
docker exec coolify sh -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

## 7. Dependencies

### PHP dependencies

Run Composer in the container:

```powershell
docker exec coolify sh -lc "cd /var/www/html && composer install"
docker exec coolify sh -lc "cd /var/www/html && composer dump-autoload"
```

### JavaScript dependencies

Install in project root:

```powershell
npm install
```

For browser tests (Pest Browser), also install Playwright:

```powershell
npm install playwright
npx playwright install
```

## 8. Running Tests

To avoid running browser tests when you want backend tests, scope your command:

```powershell
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Feature"
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Unit"
```

Run a specific test filter:

```powershell
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Feature --filter=SomeTestName"
```

## 9. Useful Logs And Debugging

Tail app logs:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f coolify
```

Tail vite logs:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f vite
```

## 10. Common Issues

### A) `PlaywrightNotInstalledException`

Install browser test dependencies:

```powershell
npm install playwright
npx playwright install
```

### B) `Undefined type 'Log'` in editor

Use an explicit import in PHP files:

```php
use Illuminate\Support\Facades\Log;
```

### C) Docker compose warnings about `PUSHER_HOST` or `PUSHER_PORT`

These are warnings from missing optional env values and do not necessarily block local development.

### D) Container says app health is `starting`

Wait 20 to 60 seconds, then re-check:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps
```

## 11. Stop, Restart, Reset

Stop containers:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml down
```

Restart fresh with data reset:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml down -v
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

Then run migrations/seed as needed:

```powershell
docker exec coolify sh -lc "cd /var/www/html && php artisan migrate:fresh --seed"
```

## 12. Recommended VS Code Habit

Keep one terminal profile for Docker commands and one for Git.

When in doubt, run commands inside the `coolify` container instead of local Windows PHP.
