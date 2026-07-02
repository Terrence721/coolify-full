# Developing Coolify In Containers (Windows)

This guide is for contributors who are new to container-based development.

## 0. 5-Command Quick Start

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

## 1. What Runs Where

- Your code stays on Windows in `C:\Users\Terre\source\repos\coolify-full`
- Docker runs Linux containers for the app and services
- The main app container is `coolify`
- VS Code edits the files on Windows and the containers see the same files through mounted volumes

## 2. One-Time Setup

1. Install Docker Desktop.
2. Start Docker Desktop and wait until ready.
3. Open PowerShell in the project root.
4. Create `.env` from the development template if needed:

```powershell
Copy-Item .env.development.example .env
```

## 3. Start The Stack

Run from the project root:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

Check status:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps
```

Expected services:

- `coolify` (app)
- `coolify-db` (Postgres)
- `coolify-redis` (Redis)
- `coolify-realtime`
- `coolify-vite`

## 4. Daily Workflow

1. Start Docker Desktop.
2. Start the stack.
3. Edit code in VS Code.
4. Run commands inside the app container.
5. Run tests.
6. Format changed PHP files.

## 5. Run Commands Inside The Container

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

## 6. Dependencies

### PHP dependencies

Run Composer in the container:

```powershell
docker exec coolify sh -lc "cd /var/www/html && composer install"
docker exec coolify sh -lc "cd /var/www/html && composer dump-autoload"
```

### JavaScript dependencies

Install in the project root:

```powershell
npm install
```

For browser tests, also install Playwright:

```powershell
npm install playwright
npx playwright install
```

## 7. Running Tests

Use folder scoping when you want to avoid browser tests:

```powershell
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Feature"
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Unit"
```

Run a specific filter:

```powershell
docker exec coolify sh -lc "cd /var/www/html && php artisan test --compact tests/Feature --filter=SomeTestName"
```

## 8. Useful Logs And Debugging

Tail app logs:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f coolify
```

Tail Vite logs:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f vite
```

## 9. Common Issues

### Playwright is missing

Install browser test dependencies:

```powershell
npm install playwright
npx playwright install
```

### `Undefined type 'Log'` in the editor

Use an explicit import:

```php
use Illuminate\Support\Facades\Log;
```

### Docker compose warns about `PUSHER_HOST` or `PUSHER_PORT`

These are optional environment values and do not always block local development.

### App health stays `starting`

Wait 20 to 60 seconds, then check status again:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps
```

## 10. Stop, Restart, Reset

Stop containers:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml down
```

Reset data and restart:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml down -v
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

Then run migrations and seeders:

```powershell
docker exec coolify sh -lc "cd /var/www/html && php artisan migrate:fresh --seed"
```

## 11. Scan Other Folders

Use these when you want to inspect a specific part of the repository.

### Syntax checks

```powershell
Get-ChildItem app -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
Get-ChildItem routes -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
Get-ChildItem database -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
Get-ChildItem tests -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

### Static analysis

```powershell
vendor/bin/phpstan analyse app --memory-limit=1G
vendor/bin/phpstan analyse routes --memory-limit=1G
vendor/bin/phpstan analyse database --memory-limit=1G
vendor/bin/phpstan analyse tests --memory-limit=1G
```

### What each folder is for

- `app`: actions, jobs, models, services, listeners, notifications
- `routes`: web, API, console, and channel routes
- `database`: migrations, seeders, and factories
- `tests`: feature, unit, and browser tests
- `config`: configuration files; syntax validation is enough for most files
- `resources/views`: Blade syntax and editor diagnostics
- `resources/js`: use `yarn build` or the Vite dev server

Start with the smallest folder that matches the code you changed, then widen the scan if needed.
