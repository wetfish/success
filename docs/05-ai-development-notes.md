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

## Verify Before You Write

Before writing or editing code that references any existing column, model, method, or relationship by name, read the actual file. Do not rely on conversation context, mental models built up over a session, or previous summaries. Names drift over time: columns get renamed, fields get dropped, methods get refactored. A name that *seems* obvious is often the most dangerous because it bypasses verification instinct.

Concrete rule: when a new file touches an existing entity, the first action is to view that entity's migration and model. When editing an existing file, re-view it immediately before each edit; previously-rendered file contents in conversation history may be stale after intermediate changes.

This applies especially when re-establishing context after a long session, after a memory compaction, or after pulling fresh code from the repo. The cost of an extra `view` call is near-zero; the cost of writing code against an imagined schema is real debugging time, partial migrations leaving the database in a broken state, and rework.

Maintain a local git checkout of the project for direct file access. Run `view` against the working tree rather than reconstructing files from memory or trying to reason about them from the conversation history. The checkout is the canonical source of truth for everything that has been committed and pushed.

At the start of a new slice, verify the local checkout is up to date with the remote. Confirm the current commit hash with the developer if there's any ambiguity — slices that build on top of recent commits will silently produce wrong code if the checkout is even one commit behind.

Once a slice is in progress, files on the developer's machine may be ahead of git: pasted in but not yet committed. This is normal — committing every intermediate state would create noise, and slices are typically committed as a unit once verified. In this situation the local checkout is no longer authoritative for those files. Do not regenerate them from memory. Ask the developer to upload the current version, and treat the upload as the new source of truth for the rest of the slice. Do not split attention between the upload and the older git copy of the same file; the upload supersedes.

## Scope Work by Directory and Intent

A single development chunk should touch one architectural layer at a time, not span the whole stack. Models, then services, then controllers and routes, then views and CSS, then tests — in that order. Each layer presented separately, pasted into the local repo, verified before moving on.

The anti-pattern this exists to prevent: producing 15-20 files spanning models, services, controllers, views, and tests in a single response, then running into tool-use limits partway through and having to regenerate files from memory. Regenerating from memory is exactly the situation where hallucinated column names, wrong method signatures, and silently-broken references creep in.

The dependency order matters because each layer has to leave the app in a buildable state. Models and value objects come first because services reference them. Services come before controllers because controllers inject them. Controllers and routes come before views because views call routes. Tests come last because they verify the integration of everything else and produce useful failure messages only when the layers they exercise actually exist.

Concrete rule: when planning a slice, list the files by directory, group them into 3-6 chunks, and present them in dependency order. If a single chunk would exceed five files, split it. If a layer change cascades into another layer (e.g., adding a model column requires updating a related controller), make the model change in chunk one and the controller change in chunk two — don't bundle them. The user verifies and pastes between chunks; this is the natural checkpoint.

## Prefer Rough Drafts with Feedback Over Upfront Clarification

When designing a feature, the temptation is to ask the developer every question that comes up: which approach, which library, which trade-off. This sounds collaborative but slows the work dramatically and produces shallower discussions than thinking out loud and producing code.

The better default: draft an opinionated first version with reasoned trade-offs spelled out inline, then iterate based on the developer's reaction. A rough draft surfaces concrete decisions the developer can react to ("I see you went with X, let's try Y instead"), which is much faster than abstract discussion ("X versus Y — what do you think?") that the developer has to ground out before they can answer.

This works because the developer can see the structure of the proposal, push back on specific choices, and iterate. The cost of an unused rough draft is small. The cost of an extra round-trip per design decision is large.

Concrete rule: when a design choice has more than one reasonable answer, pick one, document the trade-off in the code or in the response, and proceed. Save clarifying questions for actual blockers: when there's a question the developer needs to answer for the work to make sense, or when the choice has expensive long-term consequences and visible options.

The companion rule: when course-correcting after the developer pushes back, change direction directly without re-litigating. The previous draft was a starting point, not a commitment.

## Ask Only When Truly Blocked

Related but distinct from the previous rule. The signals for actually asking:

- A required input is missing and can't be inferred. ("Is the existing column called `start_date` or `started_at`?" — read the file, don't ask.)
- A choice would create irreversible work in either direction. ("Should we add a foreign key constraint or leave it loose?" — worth asking once; reverting either is real work.)
- The developer's intent could be interpreted multiple ways and the wrong interpretation would be expensive. ("When you say 'make it editable,' do you mean inline edit or a separate edit page?" — worth confirming because the implementations diverge significantly.)

Non-signals: "I want to double-check before doing this," "what if you wanted X instead," "in case you have a preference." These multiply round trips without adding signal. The developer's time is better spent reviewing a concrete draft than answering hypotheticals.

When the work is exploratory or a small scope, just do it and let the developer react. When the work is large and a wrong assumption would waste hours, ask the one specific question that resolves the largest branching point — but only that one.

## Defense in Depth on AI-Produced Inputs

AI extraction is non-deterministic. Even with a strict prompt, the model will occasionally omit a required field or produce slightly inconsistent structures. Code that consumes AI output must be robust to this, OR the developer ends up debugging the same "AI didn't include X" issue repeatedly.

The pattern: every field the AI produces gets two coordinated mitigations.

1. **Tighten the prompt.** Make the field explicitly required, with clear consequences described in the prompt rules. This reduces the omission rate substantially but doesn't eliminate it.
2. **Make the consuming code tolerant.** Where reasonable, the service or controller handles the missing field gracefully — using a fallback lookup strategy, surfacing a clear error message, or asking the user for the missing piece.

The combination is more robust than either alone. Prompt tightening catches the common case at extraction time. Tolerant services catch the residual cases AND any legacy data already in the database from before the prompt was tightened.

Concrete examples from the codebase:
- Accomplishments without `organization_name`: prompt requires it explicitly, and the confirmer falls back to global project-name lookup with disambiguation if missing.
- Drafts with missing required fields (like `description` or `start_date`): the confirmer catches `QueryException` and `InvalidArgumentException` from the model layer and converts them to user-facing flash messages, and the editable form lets the user fill in the missing piece before re-confirming.

When extending the AI extraction pipeline, apply this pattern to every new field. Trying to make either the prompt or the code perfect in isolation is a worse use of effort than making both reasonably robust.

## Verify Tag Balance After Template Edits

When editing Blade templates via `str_replace` operations that touch HTML structure — adding or removing wrapper divs, restructuring forms, changing conditional blocks — do a tag-count sanity check before presenting the file. The verification cost is one `grep -c` per tag type; the cost of a mismatched closer is a broken page layout the user has to debug.

Concrete: after any structure-changing edit to a view, count `<div>` vs `</div>`, `@if` vs `@endif`, `@foreach` vs `@endforeach`, `@php` vs `@endphp`, `<form>` vs `</form>`. They must balance. If they don't, the edit is broken and needs another pass before it's presented.

This was learned the hard way during slice 4.2 when a sloppy `str_replace` left an extra closing div behind that pushed the entire page content out of its layout container. The user caught it visually because the page rendered, just broken. Tag-balance counting would have caught it pre-paste.

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