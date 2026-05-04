# success

A career lifecycle tool. Catalog your work, tailor resumes to specific job listings, prep for interviews, track your time, send invoices, and manage the relationships that move your career forward — all in one place.

## What it does (eventually)

- **Employment catalog** — structured, deeply detailed records of everywhere you've worked, every project you've shipped, every accomplishment with the metrics to back it up
- **AI-tailored resumes** — paste a job listing, get a resume built from the most relevant evidence in your catalog, with every word traceable back to source
- **Interview prep** — practice questions generated from your actual experience, meeting notes captured during interviews and tied back to specific applications
- **Time tracking** — log hours against tasks and projects once you've landed the job
- **Invoicing** — generate timesheets and invoices from tracked time, integrated with payment processing
- **Relationship management** — track the people who matter to your career, who you owe a follow-up, who's most relevant to your next move

The first three are the priority. Everything else is the long arc of the project.

For the project's mission, design philosophy, and explicit anti-goals, read [`docs/00-mission.md`](docs/00-mission.md). For the development roadmap, see [`docs/06-planned-features.md`](docs/06-planned-features.md).

## Status

**Early planning and design.** The repo currently contains the application skeleton ([Wetfish Skeleton](https://github.com/wetfish/skeleton)) and planning documents. Schema and migrations are next.

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

- [Mission](docs/00-mission.md) — why we're building this, design philosophy, anti-goals
- [Database Schema](docs/01-database-schema.md) — table definitions, model relationships, cascade behavior
- [Services & Commands](docs/02-services-and-commands.md) — service classes, artisan commands, business logic
- [Routes & Controllers](docs/03-routes-and-controllers.md) — full route listing, controller responsibilities, request flows
- [Frontend](docs/04-frontend.md) — Tailwind/Vite setup, Blade templates, view structure, UI conventions
- [AI Development Notes](docs/05-ai-development-notes.md) — conventions for AI-assisted development with Claude
- [Planned Features](docs/06-planned-features.md) — milestones, schema design principles, deferred features, and open questions

## Open source and self-hosting

Success is licensed under the **GNU Affero General Public License v3.0** (AGPL-3.0). See [LICENSE](LICENSE) for the full text.

The AGPL is a strong copyleft license. In short: you can run, modify, and distribute Success freely, but if you operate a modified version as a network service, you must make your modifications available to the users of that service under the same license. This protects the project and its users from closed-source forks running as competing hosted offerings.

You can self-host Success on your own infrastructure. You'll need to provide your own AI provider API keys for features that depend on them. When a hosted version exists, it'll be cheap — the goal is to keep the basic features (catalog, resume builder, interview prep) affordable to people who are unemployed and price-sensitive, with paid features priced for people who are already using the tool to manage paid work.

## Contributing

The project is in early planning. The most useful thing you can do right now is read [`docs/00-mission.md`](docs/00-mission.md) and [`docs/06-planned-features.md`](docs/06-planned-features.md), open an issue if something seems wrong-headed, and stick around for when the actual code starts landing.

If you've worked on career tools, resume parsers, or AI-powered document generation and have opinions about what works and what doesn't — those opinions are wanted. Open an issue or start a discussion.