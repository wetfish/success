# success

A tool for helping job-seekers build their resumes, ace interviews, and stay on task at work

## Quick Start (Docker — Development)

Start the Docker environment:

```bash
docker compose up -d
```

Run migrations and seed default data:

```bash
docker compose exec app php artisan migrate --seed
```

Build frontend assets (run from host machine):

```bash
cd laravel && npm install && npm run build && cd ..
```

Access the app at `http://localhost:8145`.

## Docker Environment

| Container | Image | Purpose | Ports |
|-----------|-------|---------|-------|
| success-app | php:8.5-fpm (custom) | PHP-FPM with Laravel extensions and Composer | 9000 (internal) |
| success-nginx | nginx:alpine | Serves `laravel/public/`, proxies PHP to app | 8145 → 80 |
| success-db | mysql:8.0 | MySQL database | 3491 → 3306 |

## Environment Configuration

Docker Compose reads from the root `.env` file for container names, ports, and database credentials. This file is gitignored and generated during project setup. See `.env.example` for the expected variables.

Laravel's own `laravel/.env` handles application-level config (app key, database connection, session driver, etc.) and is also gitignored.

From the host machine, the database is accessible on port `3491`.

## Artisan Commands

All artisan commands run through the `app` container:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan make:model Example -m
docker compose exec app php artisan tinker
```

On production servers, drop the Docker prefix:

```bash
php artisan migrate
```

## Documentation

Detailed technical documentation lives in the [`docs/`](docs/) directory:

- [Database Schema](docs/01-database-schema.md) — table definitions, model relationships, cascade behavior
- [Services & Commands](docs/02-services-and-commands.md) — service classes, artisan commands, business logic
- [Routes & Controllers](docs/03-routes-and-controllers.md) — full route listing, controller responsibilities, request flows
- [Frontend](docs/04-frontend.md) — Tailwind/Vite setup, Blade templates, view structure, UI conventions
- [AI Development Notes](docs/05-ai-development-notes.md) — conventions for AI-assisted development with Claude
- [Planned Features](docs/06-planned-features.md) — future feature roadmap
