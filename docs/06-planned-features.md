# Planned Features

The actual development roadmap: milestones, schema design principles, deferred features, and open questions. For the project's mission and design philosophy, see [`00-mission.md`](00-mission.md).

This is a living document. Update it when decisions change.

---

## Milestones

Each milestone has an "intent" — what done looks like at the level of user value, not specification. Detailed scope gets fleshed out when a milestone becomes the active focus.

### 1. Planning *(complete)*

**Intent:** Mission, philosophy, schema principles, and milestone plan documented and agreed on. The README, `00-mission.md`, and this document exist.

### 2. Database schema *(complete)*

**Intent:** Migrations, Eloquent models, relationships, and seed data for all v1 entities. The author can run `migrate:fresh --seed` and have a development database to build against. Schema documented in `docs/01-database-schema.md`.

### 3. Basic data entry MVP *(complete)*

**Intent:** CRUD interfaces for organizations, positions, projects (including sub-projects), and accomplishments. The author can enter their actual employment history end-to-end through the UI without dropping into the database. Source-document storage, links, tags, and people UI deferred — they have schema support and will land alongside or after the AI extraction pipeline as the use case for them emerges.

### 4. AI extraction pipeline *(current)*

**Intent:** Paste raw text (interview prep, brag doc, performance review). Get a draft set of structured records to review, edit, and confirm. Confirmed records get linked back to the source document for traceability.

### 5. Resume builder

**Intent:** Capture a job listing as a structured entity. Generate a tailored resume drawing from the catalog. Save the generated resume as an immutable artifact tied to the application. Track application outcomes (applied, interviewing, offered, rejected, ghosted).

### 6. Interview prep

**Intent:** Generate practice questions from the user's actual experience, formatted for STAR-style answers. Capture meeting notes during interviews and tie them back to specific applications and the people who interviewed you.

### 7. Time tracking

**Intent:** Log hours against tasks and projects once employed. Carries forward into the post-job phase of the career lifecycle. Designed to be usable as a standalone tracker, not just a feeder for invoicing.

### 8. Invoicing

**Intent:** Generate timesheets and invoices from tracked time. Integrate with payment processing (Stripe or similar). Useful for contractors, freelancers, and anyone with billable client relationships.

### 9. Relationship management

**Intent:** Leverage the `people` table that's been growing since milestone 2. Track follow-up cadence, notes per person, and the relationships that matter to the user's career growth. This is the long-tail feature that keeps users engaged after they've landed the job.

### 10. Multi-user / SaaS readiness

**Intent:** Add a `users` table, build authentication, run the migration that adds `user_id` foreign keys to every owned entity, build subscription handling, set up hosted deployment, and add the nullable `user_id` foreign key to `tags` for the global/personal scope distinction. Until this milestone, the app is a single-user tool that happens to be open source.

---

## Schema design principles

These are the rules the v1 schema follows. Departures from these principles should be explicitly justified.

The operational rules for AI-assisted development (including a brief "no enums" note) live in `docs/05-ai-development-notes.md`. This section captures the rationale behind those rules and adds the broader strategic principles.

### No enum columns

Database-level enums are a maintenance burden. Adding a value requires a migration, and some database engines force a column rebuild. We use `string` columns with application-level validation (Laravel's `Rule::in([...])`) for finite-but-evolving value sets. New values become a code change, not a schema change.

### Soft deletes on all major entities

Organizations, positions, projects, accomplishments, people, source documents, and links all use Laravel's `SoftDeletes` trait. Career data is too valuable to risk losing to a fat-fingered click. Tags don't need it (they're shared infrastructure).

### Auto-incrementing bigint primary keys

Laravel default. Simple, well-supported, performant. We can revisit UUIDs later if there's a strong reason; there isn't one yet.

### Timestamps everywhere

`created_at` and `updated_at` on every table. Free with Laravel's `$table->timestamps()`. Useful for debugging, auditing, and "what did I do this week" reflection later.

### Polymorphic links table

A single `links` table handles URLs and external references for organizations, projects, accomplishments, positions, and people. One table is simpler than five parallel ones, and the schema accommodates artifact types we haven't anticipated yet.

The `is_personal_appearance` flag distinguishes signature evidence (a media appearance, a conference talk, a podcast where the user is a guest) from supporting links (documentation, repos, live demos). This affects how the AI weights links during resume generation.

### Self-nesting projects

Projects have an optional `parent_project_id` referencing the same table. This lets a long-running product workstream (e.g., owning a frontend product line for three years) be a parent project, with discrete initiatives (specific features, system rebuilds, planned migrations) as child projects beneath it. Accomplishments hang off whichever level makes sense.

This structure mirrors how real software work is organized and lets the AI roll up or drill down depending on how much resume real estate is available.

### Organizations, not just companies

Employers, clients, personal projects, open source communities, volunteer work, and educational institutions are all *organizations* with a `type` field distinguishing them. This avoids inventing a separate "personal projects" entity and lets positions, projects, and accomplishments use the same model regardless of context.

### Career themes as first-class data

A separate `career_themes` table holds user-authored narrative threads. These cross organizations and projects and represent the user's framing of their own career. Themes link to the projects and accomplishments that exemplify them, and the AI uses them as the spine of tailored output.

### No user system or auth scaffolding until milestone 10

MVP runs as a single-user application. There is no `users` table, no authentication, no `user_id` foreign keys on any entity, and the default Laravel auth migrations are removed from the project. The app is intended to be self-hosted by one person dogfooding it during their job search.

The trade-off this creates is real: when multi-user support lands at milestone 10, every entity that holds user data needs a migration to add a `user_id` foreign key. That's roughly ten tables. Mechanical work, half a day with concentrated effort.

We accepted this cost because the alternative — carrying nullable `user_id` columns everywhere from day one with no users table to constrain them — creates worse problems. Future contributors would see the columns and assume there's a user system. Factories would need to invent user references that point at nothing. Every new table going forward would have to remember to include the column even though it's structurally meaningless. The schema would lie about its semantics for an indefinite period of time.

Honest schema now, migration later, is the better path.

### Tags: flat and global for MVP

The `tags` table is intentionally minimal: `name`, `category`, `description`. No scope column, no `user_id`, no aliases-with-scope. Every tag in MVP is effectively global because there's only one user.

When multi-user support lands at milestone 10, a nullable `user_id` foreign key gets added to `tags`: `null` means a global tag (visible to all users), a populated `user_id` means a personal tag (visible only to that user). This achieves the "global with a user-scoped escape hatch" pattern without needing a separate `scope` column whose values would just be derivable from whether `user_id` is null. The data model becomes the documentation.

This was a deliberate simplification from an earlier proposal that included a `scope` enum-like string. We dropped it because (a) it's redundant with the nullable user_id approach, and (b) MVP doesn't have a user concept yet, so any scoping field would be carrying meaningless data until milestone 10.

### Source documents as a peer entity

Raw notes (interview prep, performance reviews, brag docs, journal entries) get stored verbatim in a `source_documents` table. Structured records (accomplishments, projects, etc.) link back to the source document they were extracted from. This:

- Preserves voice and texture that gets lost in normalized fields
- Lets the user re-extract if the schema evolves
- Provides an audit trail for AI-extracted data

### Project date precision

Projects have a `date_precision` column (`day`, `month`, `quarter`, `year`) that governs how `start_date` and `end_date` are displayed. Internally, dates are still stored as real `date` columns (start of period for `start_date`, end of period for `end_date`), so date math works for sorting and overlap detection. The precision column is a UI hint and tells the AI how confident to be when generating resume text.

This pattern doesn't apply to positions, which are usually known to the day.

### Application-level constraints over database-level

Some integrity rules — for example, "an accomplishment must belong to either a project or a position, but not both, and not neither" — are enforced in the model layer rather than the database. Database constraints are useful but inflexible; model validators give clearer error messages and are easier to evolve.

### Monetary values stored as integer cents

Every monetary field — funding rounds, future compensation, future invoice amounts, future hourly rates — is stored as `unsignedBigInteger` in the smallest currency unit (cents for USD). Models cast at the boundary via accessors. Helpers for display and parsing live in a shared `Money` support class.

The full rationale, the alternative we rejected (`DECIMAL` with the `decimal:N` cast), and why is documented in `docs/05-ai-development-notes.md` under "Money Storage." Short version: integer arithmetic in PHP is safe by default; the DECIMAL approach silently coerces to float during arithmetic and requires `bcmath` discipline everywhere to be safe.

### Accomplishments support both points-in-time and spans

The `accomplishments` table has three date-related columns: `date`, `period_start`, and `period_end`. A point-in-time accomplishment ("shipped X on March 15") uses `date`. A span ("mentored 5 engineers from Q1 2023 through Q3 2024") uses `period_start` and `period_end`. An ongoing accomplishment ("currently leading the migration to Postgres") uses `period_start` alone with `period_end` left null.

Validation rule (model layer): exactly one of `date` or `period_start` is set. `period_end` is only meaningful when `period_start` is set.

We considered an explicit `is_ongoing` boolean but rejected it — "ongoing" is derivable from `period_start IS NOT NULL AND period_end IS NULL`, and adding the boolean creates two sources of truth that can disagree. The model exposes `isOngoing()` as a method instead.

---

## Deferred features

Things we explicitly considered and decided to build later. Each is designed-around so it slots in without schema upheaval.

| Feature | Why deferred | What protects against painful migration |
|---|---|---|
| Compensation history (`compensation_events` table) | Not used in resume generation; future feature | Add a new table; existing positions stay unchanged |
| Person-organization history | Single-user mode doesn't need it; useful for relationship management later | Add a `person_organization_history` table; current `current_organization_id` becomes a denormalized convenience field |
| Project-to-project relationships (depends_on, extends, etc.) | Self-nesting handles the most common case; explicit relationships matter for advanced framing | Add a `project_relationships` table later |
| Decision logs | `rationale` field on projects covers 80% | Promote to its own table when interview prep features need richer structure |
| Accomplishment variants (per-application rewrites) | Belongs with the resume builder | Build alongside the resume generator |
| Job listings, applications, generated resumes | Resume builder milestone | Whole separate phase of the schema, additive |
| References, certifications, education | Trivial flat tables; no schema risk | Add when needed |
| User accounts / multi-tenancy | Single-user dogfood phase first; carrying nullable `user_id` columns with no users table would be misleading | When milestone 10 lands: add a `users` table, then add `user_id` foreign keys via migration to roughly ten entity tables (organizations, positions, projects, accomplishments, people, source_documents, career_themes, tags, etc.), backfilling all existing rows to point at the dogfood user |

---

## Open questions

Decisions still pending. Each will need to be resolved before the relevant milestone.

### AI provider selection

Anthropic's Claude API is the leading candidate for the extraction and generation features. Open question: do we abstract behind a provider-agnostic interface from day one (more work, more flexibility), or commit to one provider and refactor later if needed (less work, harder to switch)? Lean: abstraction layer, but only for the small surface area we actually use.

### Hosting strategy for the eventual SaaS

Single-tenant per user (one database per customer)? Multi-tenant with row-level scoping? Multi-tenant changes some schema decisions (especially how the eventual `tags.user_id` foreign key behaves). Doesn't need to be answered until milestone 10.

### Monetization model specifics

The README outlines the rough shape (cheap basic tier, higher-priced advanced tier). Actual pricing depends on real costs of AI inference per user, which we won't know until we've dogfooded the AI features. To be revisited after milestone 5.

### Privacy of source documents

If a user pastes notes that mention a confidential project codename, it lives in `source_documents` indefinitely. Worth thinking about retention policies, encryption at rest, and a "scrub these notes after extraction" option. Not blocking MVP, but should be decided before any multi-user release.

---

## Process notes

- **Schema changes are roadmap-worthy.** When the schema gains or loses a table, this document updates in the same PR.
- **Decisions get captured here, not in chat.** If a design conversation reaches a conclusion, it lands in this file or in `00-mission.md`. Otherwise it gets lost.
- **The anti-goals in `00-mission.md` are sacred.** Re-read them before approving any feature that pattern-matches to "wouldn't it be cool if Success also did X."