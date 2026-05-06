# AI Development Notes

This project is being built with the assistance of Claude (Anthropic). The following conventions must be maintained for consistent, high-quality output. These notes serve as a reference for both the AI assistant and the developer.

## Code Block Formatting

Always use an explicit language identifier on every code block (e.g., `bash`, `ini`, `text`, `php`). Include a descriptive title line before each block.

Bare ` ``` ` without a language tag causes consecutive blocks to merge into a single block in the Claude chat renderer. This was identified early and must be avoided throughout all documentation and chat output.

## File Path References

When referencing files for the developer to open, always provide full file paths relative to the repo root using `codium` commands:

```bash
codium README.md
codium docker/php/custom.ini
codium laravel/app/Http/Controllers/ExampleController.php
```

This convention ensures the developer can copy-paste commands directly without needing to figure out where a file lives.

## Artifact Ordering

When providing downloadable file artifacts alongside a list of `codium` commands, the `codium` commands must be listed in the same order as the artifacts appear in the download list. This prevents confusion when the developer is opening files sequentially to paste content into.

For example, if three artifacts are provided in order — Controller, View, Routes — the commands should be:

```bash
codium laravel/app/Http/Controllers/ExampleController.php
codium laravel/resources/views/example/index.blade.php
codium laravel/routes/web.php
```

## Artifact Naming

When providing multiple files with the same filename (e.g., multiple `index.blade.php` files), use descriptive artifact names so they are distinguishable in the download list. For example: "Users index.blade" and "Settings index.blade" rather than two files both named "index.blade".

## Artisan Commands

All Laravel artisan commands should be run via Docker Compose during local development. The `app` container's working directory is already set to the Laravel project root:

```bash
docker compose exec app php artisan make:migration create_example_table
docker compose exec app php artisan migrate
```

On production servers where Laravel runs directly, drop the Docker prefix:

```bash
php artisan migrate
```

## Migration Generation

When generating multiple migrations in sequence, add `sleep 1` between commands to ensure unique timestamps. MySQL requires referenced tables to exist before foreign keys point to them, and alphabetical ordering of same-timestamp migrations can cause failures:

```bash
docker compose exec app php artisan make:migration create_first_table
sleep 1
docker compose exec app php artisan make:migration create_second_table
```

## Page References

Do not add navigation links, buttons, or URLs pointing to pages that have not been built yet. Build the pages first, then add the references. This avoids broken links and confusion during development.

## Input Cleaning

All numeric form inputs should be cleaned server-side to strip commas, currency symbols, and whitespace before validation. Users should be able to type `$50,000.00` or `50,000` without errors.

## Validation Errors

All forms must display validation errors visibly to the user (typically in red text above or within the form) and preserve `old()` input values so users don't have to retype after a failure.

## Form-Layer vs. Model-Layer Validation

Model-level invariants (the `validateInvariants()` methods on entities) are the structural guarantee — they fire on every save, including programmatic creation in tests, future AI extraction, and tinker commands. They're the floor.

But model-level invariants throw exceptions, and an exception bubbling up from the controller's `Model::create()` call results in a 500 response. For any invariant that can be triggered by user input through a form, there must also be a corresponding form-layer rule so the user gets a friendly validation error instead of a stack trace. The model invariant stays as the structural backstop; the form rule provides the UX.

When writing form-layer cross-field rules, prefer Laravel's built-in cross-field validators (`required_without`, `prohibits`, `prohibited_unless`, etc.) over custom closure rules. Built-in rules operate on the validated/normalized data; closures that read `request()->input(...)` see the raw original request, bypassing any `prepareForValidation()` normalization and producing inconsistent behavior.

## Schema Conventions

All status and type fields use string columns instead of MySQL ENUMs. ENUMs are difficult to modify in production migrations and cause issues with schema diffing tools. Expected values are documented in the schema docs and enforced in application logic.

## Money Storage

All monetary values are stored as `unsignedBigInteger` in the smallest currency unit (cents for USD). Models cast these to `integer` for type safety on read but otherwise expose the raw cents value — no conversion happens inside the model. Conversion to and from human-readable dollar strings happens at the application boundary (form requests, controllers, views, AI prompt construction) using the shared `App\Support\Money` helper class.

The rationale: integer arithmetic in PHP is safe by default (no float rounding errors), and a single storage convention across every monetary field means one set of helpers handles display and input parsing everywhere. Keeping the model layer free of conversion magic also makes tests trivial — set integer cents, assert integer cents — without surprising round-trips through accessors.

The alternative — `DECIMAL(n, m)` columns with Laravel's built-in `decimal:N` cast — is technically defensible but introduces a footgun: the `decimal` cast returns a string, and PHP arithmetic on those strings silently coerces to float, negating the precision the column was meant to preserve. Using DECIMAL safely requires `bcmath` or a money library throughout the codebase, which is more discipline to maintain than necessary for this project.

Laravel itself does not recommend a specific approach; both options are supported. Integer cents was chosen for arithmetic safety and consistency.

This applies to every monetary field without exception: funding rounds, future compensation events, time tracking rates, invoice line items, totals, taxes.

## Cache, Queue, and Session Drivers

The MVP uses file-based drivers configured via `.env`:

```ini
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
CACHE_STORE=file
```

The Laravel default `database` drivers and their associated migrations (`create_cache_table`, `create_jobs_table`) were removed during initial setup, alongside `create_users_table`.

The reasoning: file-based drivers are simpler for a single-user self-hosted deployment, require no schema infrastructure, and meet MVP needs. Sessions persist to `storage/framework/sessions/`, cache to `storage/framework/cache/`, and the `sync` queue connection runs jobs synchronously in the same request rather than queueing them at all.

When async work becomes a real requirement — long-running AI calls, scheduled extraction jobs, email delivery — the queue driver gets revisited and a real driver (database, Redis, or similar) is added back. When the app moves to a multi-user hosted environment at milestone 10, all three drivers should be re-evaluated against production needs (likely Redis for cache and sessions, a real queue driver for jobs).

## Dependencies

Avoid adding external dependencies — npm packages, Composer packages, third-party CDNs, hosted fonts, hosted analytics, hosted asset libraries — unless absolutely necessary. Every dependency is attack surface for supply-chain compromise, a potential privacy leak (CDNs see every visitor's IP), and a reason the app can break or look wrong when offline.

The default answer is "no" until a dependency clearly earns its place. When a dependency is genuinely needed, it goes through review: what does this give us that we can't easily build ourselves, what's the maintenance status, what's the install footprint, and is this tradeoff documented somewhere a future contributor will find it?

The boring path is usually right. System fonts instead of custom typography. Hand-built Tailwind components instead of UI libraries. Plain CSS instead of preprocessor add-ons. Stock Laravel and Vite instead of starter kits. The skeleton already includes everything needed to build a real app; reach for new dependencies only when the absence of one is a concrete, recurring problem — not preemptively because something looks nice.

This rule is especially load-bearing for AI-assisted development. AI-generated code tends to reach for popular libraries because that's what training data shows. The training data does not know about this project's preferences. When in doubt, write the code by hand or skip the feature.

## Privacy

Avoid committing real personal data, financial institution names, or other sensitive information to the repository. Use generic placeholders where needed.