# Database Schema

The v1 schema for Success. Every table, column, relationship, and validation rule the MVP relies on. When a database question comes up while writing code, this is the document that answers it.

For the *reasoning* behind structural choices (why no enums, why integer cents, why no `user_id` columns yet), see [`06-planned-features.md`](06-planned-features.md). This document describes *what is*, not *why*.

---

## Conventions applied to every table

Rather than repeat these on every table, they apply globally unless explicitly overridden:

- **Primary keys.** Every table has an `id` column: auto-incrementing `bigInteger`, unsigned, primary key. Laravel's `bigIncrements()`.
- **Timestamps.** Every table has `created_at` and `updated_at` columns. Laravel's `$table->timestamps()`.
- **Soft deletes.** Every entity table (organizations, positions, projects, accomplishments, people, source_documents, links, career_themes, funding_rounds) has a nullable `deleted_at` column via Laravel's `$table->softDeletes()`. Pure join tables (e.g., `accomplishment_collaborators`) and reference tables (`tags`, `tag_aliases`) do not.
- **String columns for finite value sets.** No MySQL ENUMs anywhere. Status, type, kind, category, and similar fields are plain `string` columns, validated against an accepted list in the model layer using Laravel's `Rule::in([...])`. This includes seemingly enum-shaped fields like `employment_type`, `visibility`, `contribution_level`.
- **Money fields.** Stored as `unsignedBigInteger` representing the smallest currency unit (cents for USD). Models cast to/from human-readable values via accessors. See `docs/05-ai-development-notes.md` for the full rationale.
- **Foreign keys.** Always typed `unsignedBigInteger` matching the parent's primary key. Cascade behavior (`onDelete`) is specified per relationship below; the global summary is in the "Cascade behavior" section near the end of this document.
- **No user_id columns.** MVP is single-user. There is no `users` table yet. When milestone 10 lands, `user_id` columns will be added to entity tables via migration.

---

## Tables

### Organizations and their structure

#### `organizations`

The top of the hierarchy. Covers employers, clients, personal projects, open-source orgs, volunteer orgs, and educational institutions — anything with a name that work happens at or for.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| name | string | no | |
| type | string | no | Accepted values: `employer`, `client`, `personal`, `open_source`, `volunteer`, `educational` |
| website | string | yes | |
| tagline | string | yes | Auto-enriched from website on intake |
| description | text | yes | Longer "what they do" |
| headquarters | string | yes | Free text — "NYC", "Berlin (remote-first)", "Distributed" |
| founded_year | smallInteger | yes | |
| size_estimate | string | yes | Free text bucket — "30-40", "Fortune 500", "~10" |
| status | string | yes | Accepted values: `active`, `acquired`, `defunct`, `unknown` |
| enriched_at | timestamp | yes | When auto-enrichment last populated this record |
| user_notes | text | yes | Private freeform notes |

**Relationships.** `hasMany` positions, projects, funding_rounds. `morphMany` links. `hasMany` people via `current_organization_id` (with `onDelete('set null')` on that side, so deleting an org doesn't cascade-kill people records).

**Indexes.** Standard primary key index. Add an index on `name` for lookup performance during data entry (auto-suggest "have I worked at this company before?").

#### `funding_rounds`

A separate table from day one rather than columns on `organizations`, because organizations frequently have multiple rounds and we want to capture the full history.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| organization_id | bigInteger | no | FK → organizations |
| round_name | string | no | Free text — "Seed", "Series A", "Series B", "IPO", "Bootstrapped" |
| round_date | date | yes | |
| amount_raised | unsignedBigInteger | yes | Stored in cents |
| currency | string | yes | ISO 4217 code (e.g., "USD"). Defaults to `"USD"` in the model layer |
| lead_investor | string | yes | |
| notes | text | yes | |

**Relationships.** `belongsTo` organization.

**Cascade.** `organization_id` → `onDelete('cascade')`. If the parent org is hard-deleted, its rounds go too. (Soft-delete on the org leaves rounds intact, which is the desired behavior for accidental-delete recovery.)

---

### People and connections

#### `people`

For collaborators, managers, references, and the eventual relationship-management feature. People are modeled once and referenced from multiple places (positions point at managers, accomplishments point at collaborators).

| Column | Type | Nullable | Notes |
|---|---|---|---|
| name | string | no | |
| current_title | string | yes | |
| current_organization_id | bigInteger | yes | FK → organizations |
| email | string | yes | |
| relationship_type | string | yes | Accepted values: `manager`, `report`, `peer`, `mentor`, `mentee`, `client`, `vendor`, `recruiter`, `other` |
| user_notes | text | yes | |

**Relationships.** `belongsTo` current_organization. `morphMany` links — LinkedIn URL, personal site, GitHub, etc. live in the `links` table rather than as columns here.

**Cascade.** `current_organization_id` → `onDelete('set null')`. A person outliving the organization they last worked at is normal data.

**Notes.** A future `person_organization_history` table will track job changes over time. When that lands, `current_organization_id` becomes a denormalized convenience field that mirrors the most recent history row. For MVP, single field is sufficient.

---

### Employment

#### `positions`

A specific role at an organization. Multiple positions per organization is allowed and expected (promotions, internal team moves).

| Column | Type | Nullable | Notes |
|---|---|---|---|
| organization_id | bigInteger | no | FK → organizations |
| title | string | no | |
| informal_title | string | yes | When the real scope didn't match the official title |
| employment_type | string | no | Accepted values: `full_time`, `part_time`, `contract`, `freelance`, `internship`, `advisor`, `volunteer`, `founder` |
| start_date | date | no | |
| end_date | date | yes | Null = currently in this role |
| location_arrangement | string | no | Accepted values: `remote`, `hybrid`, `onsite` |
| location_text | string | yes | Free text — "Global team, distributed", "SF HQ, hybrid 2x/week" |
| team_name | string | yes | "Terminal Web team", "Platform Infra" |
| team_size_immediate | smallInteger | yes | |
| team_size_extended | smallInteger | yes | Roughly how many you collaborated with regularly |
| reports_to_person_id | bigInteger | yes | FK → people |
| mandate | text | yes | What you were hired to do, if it was clearly defined. Optional |
| reason_for_leaving | string | yes | Accepted values: `laid_off`, `quit_for_opportunity`, `quit_for_personal`, `contract_ended`, `company_wound_down`, `terminated`, `still_employed`, `other` |
| reason_for_leaving_notes | text | yes | Private context, never goes on a resume |
| user_notes | text | yes | |

**Relationships.** `belongsTo` organization, reports_to (as a `person` relationship pointing through `reports_to_person_id`). `hasMany` projects, accomplishments. `morphMany` links.

**Cascade.** `organization_id` → `cascade`. `reports_to_person_id` → `set null`.

**Notes.** No `summary` field. Position-level summaries are derived from underlying projects and accomplishments at render time, not stored. The `mandate` field is the deliberate exception — it captures *what you were hired to do*, which is genuinely top-down information that doesn't emerge from project data.

---

### Work output

#### `projects`

The unit of work within a position (or, for personal projects, within an organization without a position). Self-nesting via `parent_project_id` lets a long-running product workstream contain discrete sub-initiatives.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| organization_id | bigInteger | no | FK → organizations |
| position_id | bigInteger | yes | FK → positions. Null = personal/side project at this org, not part of formal employment |
| parent_project_id | bigInteger | yes | FK → projects (self-reference) |
| name | string | no | Internal name |
| public_name | string | yes | If different from internal name |
| description | text | yes | One-line "what is this thing" |
| problem | text | yes | What was broken or missing |
| constraints | text | yes | What you couldn't do, and why |
| approach | text | yes | How you tackled it |
| outcome | text | yes | What happened |
| rationale | text | yes | Why this approach over alternatives |
| start_date | date | yes | |
| end_date | date | yes | Null = ongoing or unbounded |
| date_precision | string | no | Accepted values: `day`, `month`, `quarter`, `year`. Defaults to `month` |
| visibility | string | no | Accepted values: `public`, `open_source`, `internal`, `confidential` |
| status | string | yes | Accepted values: `live`, `archived`, `killed`, `prototype`, `ongoing` |
| contribution_level | string | no | Accepted values: `lead`, `core`, `contributor`, `occasional`, `reviewer` |
| contribution_type | string | yes | Free text — "feature_development, maintenance" |
| team_size | smallInteger | yes | Size of team on this specific project (may differ from position-level team_size) |
| user_notes | text | yes | |

**Relationships.** `belongsTo` organization, position, parent_project. `hasMany` child_projects (via `parent_project_id`), accomplishments. `belongsToMany` tags via `taggables` (polymorphic). `morphMany` links. `belongsToMany` source_documents via `project_source_documents`. `belongsToMany` career_themes via `career_theme_projects`.

**Cascade.** `organization_id` → `cascade`. `position_id` → `set null`. `parent_project_id` → `set null` (sub-project survives if parent is deleted; the relationship just breaks).

**Notes on `date_precision`.** Internally `start_date` and `end_date` are stored as real `date` columns. For non-day precision, the convention is to store the first day of the period for `start_date` (e.g., Q2 2023 → `2023-04-01`) and the last day of the period for `end_date` (e.g., Q2 2023 → `2023-06-30`). This keeps date math working for sorting and overlap detection. The `date_precision` column tells the UI how to render the dates and tells the AI how confident to be about the timeframe.

#### `accomplishments`

The unit of evidence. Belongs to either a project or a position, never both, never neither (enforced in the model layer).

| Column | Type | Nullable | Notes |
|---|---|---|---|
| project_id | bigInteger | yes | FK → projects |
| position_id | bigInteger | yes | FK → positions. Mutually exclusive with project_id |
| description | text | no | What you did |
| impact_metric | string | yes | "p99 latency", "support ticket volume" |
| impact_value | string | yes | "47", "$40k", "0" — string so we can hold ranges, percentages, etc. |
| impact_unit | string | yes | "percent reduction", "annual savings" |
| confidence | tinyInteger | yes | 1-5: how comfortable would you be discussing this in an interview |
| prominence | tinyInteger | yes | 1-5: signature work vs. filler |
| context_notes | text | yes | Background not for the resume |
| date | date | yes | Single point in time. Mutually exclusive with period_start/period_end |
| period_start | date | yes | Start of an ongoing or completed span |
| period_end | date | yes | End of a completed span. Null + period_start set = ongoing |

**Relationships.** `belongsTo` project, position. `belongsToMany` tags via `taggables`. `belongsToMany` people via `accomplishment_collaborators`. `morphMany` links. `belongsToMany` source_documents via `accomplishment_source_documents`. `belongsToMany` career_themes via `career_theme_accomplishments`.

**Cascade.** `project_id` → `cascade`. `position_id` → `cascade`.

**Validation rules (enforced in the model).**

- Exactly one of `project_id` or `position_id` must be set; both null is invalid, both set is invalid.
- Exactly one of `date` or `period_start` must be set; both null is invalid, both set is invalid.
- `period_end` is only meaningful when `period_start` is set; `period_end` without `period_start` is invalid.
- When both `period_start` and `period_end` are set, `period_end` must be on or after `period_start`.
- `confidence` and `prominence`, when set, must be integers from 1 to 5 inclusive.

**Helper methods on the model.**

- `isOngoing()` — returns `true` when `period_start IS NOT NULL AND period_end IS NULL`. No stored boolean field; the state is derived.
- `isPointInTime()` — returns `true` when `date IS NOT NULL`.
- `isSpan()` — returns `true` when `period_start IS NOT NULL`.

---

### Skills and tags

#### `tags`

Flat reference table for skills, technologies, domains, and similar concepts. Tags are referenced from projects, accomplishments, organizations, positions, and source_documents via the polymorphic `taggables` table.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| name | string, unique | no | Canonical form |
| category | string | yes | Soft hint. Accepted values: `language`, `framework`, `tool`, `protocol`, `domain`, `methodology`, `vendor`, `hardware`, `concept` |
| description | text | yes | Optional, useful for obscure or specialized tags |

**Relationships.** `hasMany` tag_aliases. `morphedByMany` projects, accomplishments, organizations, positions, source_documents (all through the `taggables` polymorphic join).

**No soft deletes.** Tags are shared infrastructure. Hard-delete with cleanup of orphaned `taggables` rows separately if needed.

**Notes.** No `user_id`, no `scope`. MVP has only one user, so tags are effectively global. When multi-user lands at milestone 10, a nullable `user_id` foreign key gets added: null = global tag, populated = personal tag.

#### `tag_aliases`

Lets multiple inputs ("Postgres", "PostgreSQL", "postgres") resolve to the same canonical tag.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| tag_id | bigInteger | no | FK → tags |
| alias | string, unique | no | The non-canonical form |

**Relationships.** `belongsTo` tag.

**Cascade.** `tag_id` → `cascade`.

#### `taggables` (polymorphic join)

Many-to-many between tags and any taggable entity.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| tag_id | bigInteger | no | FK → tags |
| taggable_type | string | no | Eloquent model class name |
| taggable_id | bigInteger | no | ID of the related entity |

**No soft deletes, no timestamps.** Pure join table.

**Indexes.** Compound index on `(taggable_type, taggable_id)` for fast lookups when fetching all tags for an entity. Compound index on `tag_id` for the reverse direction.

---

### Links and external evidence

#### `links`

A polymorphic table holding all URLs and external references — for organizations, projects, accomplishments, positions, and people. One table replaces what could have been five parallel ones.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| linkable_type | string | no | Eloquent model class name |
| linkable_id | bigInteger | no | ID of the related entity |
| type | string | no | See accepted values below |
| url | string | yes | Null is allowed for `internal_doc` type — references documents that exist but aren't shareable |
| title | string | yes | |
| description | text | yes | |
| is_personal_appearance | boolean | no | Default `false`. True when the user appears personally (interview, talk, podcast) |
| date | date | yes | When the artifact was published or recorded |

**Accepted values for `type`** (validated in the model): `website`, `twitter`, `github`, `linkedin`, `blog`, `slack`, `careers`, `repo`, `documentation`, `live_demo`, `media_appearance`, `talk`, `blog_post`, `case_study`, `internal_doc`, `other`.

Some types are context-specific (e.g., `slack` makes sense for organizations but not for projects; `repo` makes sense for projects but not for organizations). The database accepts any value from the list regardless of the linkable type — the UI is responsible for surfacing context-appropriate types when adding a link.

**Relationships.** `morphTo` linkable.

**Indexes.** Compound index on `(linkable_type, linkable_id)` for fast lookups.

**Notes.** The `is_personal_appearance` flag distinguishes signature evidence (a media appearance, a conference talk, a podcast where the user is featured) from supporting evidence (documentation, repos, live demos). This affects how the AI weights links when building tailored resumes — interviews and talks become portfolio items; docs links become supporting references.

---

### Source documents (raw notes)

#### `source_documents`

For the "paste your notes" entry path. Raw, unstructured text gets stored here and structured records get extracted from it.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| title | string | yes | |
| kind | string | no | Accepted values: `interview_prep`, `performance_review`, `brag_doc`, `journal`, `meeting_notes`, `other` |
| body | text | no | The raw notes verbatim |
| context_date | date | yes | When the notes were written |
| context_notes | text | yes | What occasion ("Interview prep for Stripe, Aug 2025") |

**Relationships.** `belongsToMany` accomplishments via `accomplishment_source_documents`. `belongsToMany` projects via `project_source_documents`. `morphedByMany` tags via `taggables`.

**Notes.** Source documents are the audit trail for AI-extracted records. When a project or accomplishment is created via the extraction pipeline, the relationship to its originating source document is recorded so the user can re-extract later if the schema evolves, and so the original voice and texture is preserved beyond what makes it into normalized fields.

#### `accomplishment_source_documents` (join)

| Column | Type | Nullable | Notes |
|---|---|---|---|
| accomplishment_id | bigInteger | no | FK → accomplishments |
| source_document_id | bigInteger | no | FK → source_documents |

**Cascade.** Both FKs → `cascade`.

**Notes.** Pure join table — no timestamps, no soft deletes.

#### `project_source_documents` (join)

| Column | Type | Nullable | Notes |
|---|---|---|---|
| project_id | bigInteger | no | FK → projects |
| source_document_id | bigInteger | no | FK → source_documents |

**Cascade.** Both FKs → `cascade`.

Two separate join tables (rather than one polymorphic one) because there are only two extractable entity types in MVP and flat join tables are easier to query than polymorphic ones for relationships this simple.

---

### Career themes

#### `career_themes`

User-authored narrative threads — the way the user frames their own career across organizations and projects. The AI uses these as the spine of tailored output: pick the relevant theme(s) for a given job, then pull the best evidence under each.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| name | string | no | "Distributed systems with a privacy bent" |
| description | text | yes | Longer elaboration of the theme |
| display_order | integer | no | Default `0`. For user-controlled sorting in UI |

**Relationships.** `belongsToMany` projects via `career_theme_projects`. `belongsToMany` accomplishments via `career_theme_accomplishments`.

#### `career_theme_projects` (join)

| Column | Type | Nullable | Notes |
|---|---|---|---|
| career_theme_id | bigInteger | no | FK → career_themes |
| project_id | bigInteger | no | FK → projects |

**Cascade.** Both FKs → `cascade`.

#### `career_theme_accomplishments` (join)

| Column | Type | Nullable | Notes |
|---|---|---|---|
| career_theme_id | bigInteger | no | FK → career_themes |
| accomplishment_id | bigInteger | no | FK → accomplishments |

**Cascade.** Both FKs → `cascade`.

---

### Accomplishment collaborators

#### `accomplishment_collaborators` (join)

Many-to-many between accomplishments and people, with a small bit of context per relationship.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| accomplishment_id | bigInteger | no | FK → accomplishments |
| person_id | bigInteger | no | FK → people |
| role_on_accomplishment | string | yes | Free text — "code reviewer", "design partner", "co-author" |

**Cascade.** Both FKs → `cascade`.

**Notes.** This join table has an extra column (`role_on_accomplishment`) and would benefit from `created_at` / `updated_at` timestamps since the relationship has its own lifecycle. Add timestamps but no soft deletes.

---

## Cascade behavior summary

For quick reference, here's every foreign key and what happens on parent deletion:

| Relationship | On delete |
|---|---|
| `positions.organization_id` → organizations | cascade |
| `projects.organization_id` → organizations | cascade |
| `projects.position_id` → positions | set null |
| `projects.parent_project_id` → projects | set null |
| `accomplishments.project_id` → projects | cascade |
| `accomplishments.position_id` → positions | cascade |
| `funding_rounds.organization_id` → organizations | cascade |
| `people.current_organization_id` → organizations | set null |
| `positions.reports_to_person_id` → people | set null |
| `tag_aliases.tag_id` → tags | cascade |
| `taggables` (both sides) | cascade |
| `links` (polymorphic) | cascade — handled at application layer since polymorphic FKs aren't enforced at DB level |
| `accomplishment_source_documents` (both sides) | cascade |
| `project_source_documents` (both sides) | cascade |
| `career_theme_projects` (both sides) | cascade |
| `career_theme_accomplishments` (both sides) | cascade |
| `accomplishment_collaborators` (both sides) | cascade |

**A note on soft deletes vs. cascade.** When an entity is *soft-deleted* (`deleted_at` set), child rows are not affected — they remain pointing at a soft-deleted parent. Eloquent's default behavior excludes soft-deleted records from queries, so children effectively "orphan" until either the parent is restored (relationships work again) or the parent is hard-deleted (cascade fires). This is intentional: it makes accidental-delete recovery clean and predictable.

---

## Cross-cutting validation rules

These rules touch multiple models or aren't tied to any single column. Enforced in the model layer (form requests, observers, or model boot methods).

**Accomplishments.** Must belong to exactly one of project or position (not both, not neither). See the accomplishments table notes above for the full set of validation rules.

**Projects.** When `parent_project_id` is set, the parent project must belong to the same `organization_id`. A sub-project can't span organizations. (Self-nesting within an org is fine; cross-org parenting would be a data model error.)

**Tag aliases.** An alias's text must not collide with any existing canonical tag name across the entire `tags` table. ("Postgres" can't be both a canonical tag and an alias for "PostgreSQL".)

**Links.** When `type = internal_doc`, `url` may be null but `title` is required. For all other types, `url` is required.

---

## Tables explicitly NOT created in v1

These are documented in the deferred features table in `06-planned-features.md`. Listed here so a developer reading the schema doc has a quick "is this table missing because it's not built yet, or because I forgot something?" reference:

- `users` (no auth in MVP)
- `compensation_events`
- `person_organization_history`
- `project_relationships` (parent/child via `parent_project_id` covers MVP needs)
- `decisions` (the `rationale` field on projects covers MVP needs)
- `accomplishment_variants` (resume builder feature)
- `job_listings`, `applications`, `generated_resumes` (resume builder feature)
- `references`, `certifications`, `education`