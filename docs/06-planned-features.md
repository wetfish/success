# Planned Features

The actual development roadmap: milestones, schema design principles, deferred features, and open questions. For the project's mission and design philosophy, see [`00-mission.md`](00-mission.md).

This is a living document. Update it when decisions change.

---

## Milestones

Each milestone has an "intent" — what done looks like at the level of user value, not specification. Detailed scope gets fleshed out when a milestone becomes the active focus.

### 1. Planning *(current)*

**Intent:** Mission, philosophy, schema principles, and milestone plan documented and agreed on. The README, `00-mission.md`, and this document exist.

### 2. Database schema

**Intent:** Migrations, Eloquent models, relationships, and seed data for all v1 entities. The author can run `migrate:fresh --seed` and have a development database to build against. Schema documented in `docs/01-database-schema.md`.

### 3. Basic data entry MVP

**Intent:** CRUD interfaces for organizations, positions, projects (including sub-projects), accomplishments, people, links, and tags. The author can enter their actual employment history end-to-end through the UI without dropping into the database. Source-document storage is in place even if the AI extraction pipeline isn't.

### 4. AI extraction pipeline

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

**Intent:** Proper authentication, subscription handling, hosted deployment, tag scope promotion workflow, support for users beyond the author. Until this milestone, the app is a single-user tool that happens to be open source.

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

### Tags: global with a user-scoped escape hatch

The `tags` table includes a `scope` column (`global` or `user`) and a nullable `user_id`. User-created tags default to user scope and can be promoted to global later. This:

- Keeps potentially sensitive tags (internal codenames, confidential client domains) private by default
- Allows a shared vocabulary for the majority of tags that aren't sensitive
- Defers the "who can promote a tag to global" workflow until SaaS readiness, while protecting the data shape from breaking when that workflow lands

In single-user mode, the distinction is invisible — everything the user creates is theirs.

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
| User accounts / multi-tenancy | Single-user dogfood phase first | Skeleton ships with auth scaffolding; tag scoping already designed for this |

---

## Open questions

Decisions still pending. Each will need to be resolved before the relevant milestone.

### AI provider selection

Anthropic's Claude API is the leading candidate for the extraction and generation features. Open question: do we abstract behind a provider-agnostic interface from day one (more work, more flexibility), or commit to one provider and refactor later if needed (less work, harder to switch)? Lean: abstraction layer, but only for the small surface area we actually use.

### Hosting strategy for the eventual SaaS

Single-tenant per user (one database per customer)? Multi-tenant with row-level scoping? Multi-tenant changes some schema decisions (especially around tag scoping). Doesn't need to be answered until milestone 10.

### Monetization model specifics

The README outlines the rough shape (cheap basic tier, higher-priced advanced tier). Actual pricing depends on real costs of AI inference per user, which we won't know until we've dogfooded the AI features. To be revisited after milestone 5.

### How to handle ongoing accomplishments

"Mentored five engineers over my tenure" is real, important, and doesn't fit a single date. Probably wants an `is_ongoing` flag on accomplishments plus optional `period_start` / `period_end` separate from a single `date` column. Decide before the accomplishments UI ships.

### Privacy of source documents

If a user pastes notes that mention a confidential project codename, it lives in `source_documents` indefinitely. Worth thinking about retention policies, encryption at rest, and a "scrub these notes after extraction" option. Not blocking MVP, but should be decided before any multi-user release.

---

## Process notes

- **Schema changes are roadmap-worthy.** When the schema gains or loses a table, this document updates in the same PR.
- **Decisions get captured here, not in chat.** If a design conversation reaches a conclusion, it lands in this file or in `00-mission.md`. Otherwise it gets lost.
- **The anti-goals in `00-mission.md` are sacred.** Re-read them before approving any feature that pattern-matches to "wouldn't it be cool if Success also did X."