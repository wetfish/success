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

**Intent:** CRUD interfaces for organizations, positions, projects (including sub-projects), and accomplishments. The author can enter their actual employment history end-to-end through the UI without dropping into the database. Source-document UI was deferred initially and landed with milestone 4. Links UI followed in a post-milestone-4 sweep, attaching to organizations, projects, positions, and accomplishments (and to people once that slice lands). Tags UI landed in the same sweep, with a reusable autocomplete picker that surfaces existing tags and their aliases on every parent form. People UI landed next, including a collaborator picker with free-text roles on position, project, and accomplishment forms — the people-attachment shape converged across all three parent entity types during this work, so manager relationships now live as `role_on_position = "Manager"` rather than a dedicated FK.

### 4. AI extraction pipeline *(current — mini-slices 4.1 through 4.5 complete, 4.6 in progress)*

**Intent:** Paste raw text (interview prep, brag doc, performance review). Get a draft set of structured records to review, edit, and confirm. Confirmed records get linked back to the source document for traceability.

**Status by mini-slice:**

- **4.1 Home page draft counts & document show page summary** — complete
- **4.2 Single-draft review page with progress bar** — complete
- **4.3 Reject action with cascading rejection** — complete
- **4.3.1 All-drafts-browsable & restore** — complete. Rejected drafts stay visible in the review queue with a status badge; restore action flips back to pending. Made the implicit "queue is just pending" assumption explicit and put rejected drafts back in reach.
- **4.4 Confirm action with editable form** — complete. Pending drafts render as a form whose fields come from `DraftFieldSchema`. Submit merges form data into the payload, saves, then attempts confirmation via `DraftConfirmer`. Failures preserve edits and surface a flash message.
- **4.5 Duplicate detection & merge UI** — complete. `DuplicateDetector` surfaces candidate records when viewing a draft. The merge UI is a side-by-side editor showing existing vs draft values per field, with per-textarea-field on-demand AI synthesis. When more than one candidate matches, the user picks before the editor opens. On merge: target record updated, draft marked `merged`, dependent drafts' parent-name references rewritten to the merged name.
- **4.6 Extend the pipeline to extract tags, people, and links** — in progress (extraction pipeline and tag review UI complete; person review UI and link review integration outstanding)

**Status of 4.6 work landed so far.**

The architecture: AI extraction emits entity drafts only, carrying nested `tags` / `collaborators` / `links` arrays in their payloads. The extracted data is immutable. Top-level `tag` / `person` / `link` review records get derived from those nested arrays by `ReviewRecordExtractor` and surface as the wizard's review steps. Review decisions affect the catalog directly (accepts create catalog tags/people, aliases create alias rows, rejects undo prior accepts). At entity-draft confirmation time, nested arrays resolve against the catalog via `TagResolver::preview` and `PersonResolver::preview` (read-only — no auto-create). A name that's been rejected at review is absent from the catalog and gets skipped at attachment; an aliased name resolves through the alias to the target tag. The extracted data is never modified; the audit trail stays intact; review decisions enforce themselves at materialization through catalog state.

Concretely shipped:

- **Extraction prompt rewritten.** `ClaudeExtractionProvider` emits entity drafts only — no top-level `tag`, `person`, or `link` records from the AI. Every entity draft can carry nested `tags`, `collaborators`, and `links` arrays. Tag nested entries are `{name, category}` objects (category from the closed `Tag::CATEGORIES` enum).
- **Nested attachment switched to preview.** `DraftConfirmer::attachNestedTags` and `attachNestedCollaborators` use the resolvers' `preview()` methods rather than `resolve()`. Names with no catalog match are skipped (no auto-create). `attachNestedLinks` creates link rows directly via the parent's morphMany; invalid link types default to `'other'` per `Link::TYPES`.
- **`ReviewRecordExtractor` service** walks pending entity drafts and derives top-level `tag`/`person`/`link` review records. Dedupes (case-insensitive name for tags and people, exact URL for links), pre-computes catalog matches via the preview methods, persists. Matched tag/person records land as `status='confirmed'` directly (no decision needed); unmatched land as `pending`. Link records always land as `pending`. Idempotent — re-running on a document with existing review records is a no-op. Wired into `SourceDocumentController::extract` so derivation runs immediately after entity drafts persist.
- **`extraction:backfill-review-records` artisan command** for retrofitting older documents and refreshing review records after catalog changes. Supports `--document=N`, `--force` (deletes pending records and redrives), and respects `--no-interaction` for scripted use.
- **Tag review wizard step (chunk 4b).** Dedicated page at `/source-documents/{doc}/review/tags`. List UI grouped by AI-emitted category, with Accept / Alias to… / Reject actions per card. Alias picker reuses the existing `tags.search` endpoint via a new single-select `alias-picker.js` module. Action endpoints return JSON only (`{ok}` or `{error}`). Card border tints by decision state — pink for approved, muted grey for rejected, default for pending. Pre-decided records (the auto-confirmed matches from derivation) show with the approved styling on initial render. `RequireTagReviewComplete` middleware gates entity-draft routes when pending tag review records exist — deep-links redirect to the tag review page.
- **Review page array-rendering fix.** Slice A from chunk 4 added a `link_list` field type to `DraftFieldSchema` and updated the entity-draft review page to render nested tag/collaborator/link arrays read-only — was a 500 prior to that fix once entity drafts started carrying nested arrays.

**Outstanding 4.6 work.**

- **Person review wizard step (chunk 4c).** Mirrors the tag review page structure but simpler — no alias mechanism (people don't have aliases yet). Symmetric `RequirePersonReviewComplete` middleware. Slots between tag review and entity-draft review in the wizard sequence.
- **Link review integration.** Link review records exist in the database from chunk 3 but have no UI; the design calls for link review to happen on each entity-draft review page (where nested links are already displayed read-only), with edits to URL/type/description and accept/reject per nested entry. Connects nested-link editing to the top-level link review records' status for audit-trail purposes.
- **Wizard index polish.** The `index` route currently routes tag → entity; needs to insert person between them when 4c lands.

**Known limitations of the tag review UI (carry into chunk 5+ or document).**

- Orphan dependency on reject: if record A's accept creates a catalog tag and record B aliases to it, rejecting A cascade-deletes B's alias row. B's status remains 'merged' with a dangling `match_record_id`. Recovery: refresh and re-decide. Edge case; not blocking.
- Server-rendered confirmed records show the extracted name as the "Accepted as X" target; JS-driven transitions use the catalog tag's canonical name. Slight discrepancy when extracted/catalog casing differs and the page hasn't been refreshed since accept. Cosmetic; fix would be eager-loading `matchedTag` in the controller.
- Batch actions (Accept all / Reject all) are deliberately deferred. The per-decision friction matches the "considered curation" intent. Easy to add later if power users surface a need.
- Re-deciding tag review records is supported by the controller (idempotent state transitions) and the JS (action buttons stay visible after a decision). Full reversibility for the people review step is still TBD when that ships.

**Status of preparatory work for 4.6.** Three chunks of foundation landed before the active plan was finalized:

- **DraftConfirmer extended** for `person` and `link` record types, and for materializing nested `tags` / `collaborators` attachments on entity drafts. Top-level link confirmation was reverted; links now materialize via nested arrays only.
- **DraftFieldSchema extended** with `boolean`, `tag_list`, `collaborator_list`, `link_list` field types and dedicated schemas for `person` and `link`.
- **Resolution services** (`TagResolver`, `PersonResolver`) extracted from `DraftConfirmer` into `app/Services/Resolution/`. Each provides `resolve()` (find-or-create) and `preview()` (read-only inspection) methods. Nested attachment uses `preview()` exclusively after chunk 4a.

**Outstanding people work** (unchanged from prior plan). Two small follow-ups: (1) inline quick-add from the person picker (create a name-only person mid-form when the typed name doesn't exist yet — schema permits this, validation already accepts name-only submissions, just need the UI affordance), and (2) extending the link picker to support Person as a linkable entity (`createForPerson` route, LinkController LINKABLE_MAP entry, match arm in `links/_section.blade.php`). Neither blocks the 4.6 chunks; both are good "polish the people surface" slices when time permits.

**Vestigial source-document tagging.** The schema-level `source_documents.tags()` polymorphic relationship is no longer used by the application — AI extraction tags entity drafts, not source documents. The `morphedByMany` and `tags()` relationship remain in place because removing them requires a migration to clean up taggables rows. Candidate for a future cleanup slice; not blocking anything.

**Cache tag statistics before SaaS launch.** The tag index page computes usage counts via a correlated subquery against `taggables` on every page load. Fine at MVP scale (hundreds of tags, a handful of references each), but it scans the full join table and will become a hotspot at scale. Add a `tag_statistics` cache table (or Redis-backed counter) before milestone 10 ships multi-user.

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

### AI provider selection *(resolved)*

Anthropic's Claude API is the provider. Abstracted behind `App\Services\Extraction\ExtractionProvider` so a `FakeExtractionProvider` can stand in for tests and a different provider could be swapped in at the service-container boundary if needed. Current production model: `claude-sonnet-4-6`. Pricing constants live in `App\Services\AiUsageTracker`.

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