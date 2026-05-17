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
| currency | string | yes | ISO 4217 code (e.g., "USD") |
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
| employment_type | string | no | Accepted values: `full_time`, `part_time`, `contract`, `freelance`, `internship`, `advisor`, `volunteer`, `founder` |
| start_date | date | no | |
| end_date | date | yes | Null = currently in this role |
| location_arrangement | string | no | Accepted values: `remote`, `hybrid`, `on_site` |
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
| title | string | no | Short scannable label, used as heading and in lists. Required at the validator level. DB has default `'Untitled Accomplishment'` so existing rows backfill cleanly during migration |
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

For the "paste your notes" entry path. Raw, unstructured text or uploaded files get stored here, and structured records get extracted from them.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| title | string | yes | |
| kind | string | no | Accepted values: `interview_prep`, `performance_review`, `brag_doc`, `journal`, `meeting_notes`, `other` |
| file_path | string | yes | Relative storage path for uploaded files. Null for pasted text |
| file_type | string | yes | Accepted values: `text`, `markdown`, `pdf`. Null for pasted text (treated as `text`) |
| body | text | yes | The textual body. For pasted text, the literal pasted content. For uploaded text and markdown files, the file contents read into the column at upload time. Null for PDF uploads — the file at `file_path` is the source, sent directly to Claude as base64 at extraction time |
| context_date | date | yes | When the notes were written |
| context_notes | text | yes | What occasion ("Interview prep for Stripe, Aug 2025") |
| review_decisions | json | yes | **Being removed.** Added in the original 3-prep chunk to track "negative space" review decisions (rejected_tags, renamed_tags, etc.). The audit-trail design that replaced it represents decisions as `extracted_records` rows of types `tag` and `person`, making this column redundant. Scheduled for removal in milestone 4.6's cleanup chunk. |

**Relationships.** `belongsToMany` accomplishments via `accomplishment_source_documents`. `belongsToMany` projects via `project_source_documents`. `morphedByMany` tags via `taggables`. `hasMany` extracted_records. `hasMany` ai_usage_events.

**Source-document tagging is vestigial.** Source documents are schema-level taggable (the polymorphic join supports it) but the relationship is unused by the application. The AI extraction pipeline tags *entity drafts* (project, position, etc.) rather than source documents themselves; user review of those tags happens via the milestone-4.6 review pages. The `morphedByMany` definition on the Tag model and the `tags()` relationship on SourceDocument remain in place because removing them requires a separate migration to clean up taggables rows; this is a candidate for a future cleanup slice. Do not include source documents as a target for the manual tag picker.

**Notes.** Source documents are the audit trail for AI-extracted records. When a project or accomplishment is created via the extraction pipeline, the relationship to its originating source document is recorded so the user can re-extract later if the schema evolves, and so the original voice and texture is preserved beyond what makes it into normalized fields.

**Extraction status is derived, not stored.** A document's status (`pending`, `completed`, `failed`) is computed from related tables — see the `isPending()`, `isCompleted()`, `isFailed()` methods on the model. This avoids the column drifting out of sync with reality. The trade-off is a small query cost on each status check; revisit if heavy index pages need it cached.

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

### AI extraction pipeline

The tables that support the extraction pipeline — turning raw `source_documents` into reviewable drafts that become real records once the user confirms.

#### `extracted_records`

Drafts produced by AI extraction. Stays in this staging table until the user confirms (the draft becomes a real organization/position/project/accomplishment), rejects (the draft is discarded), or merges (the draft is combined with an existing record).

| Column | Type | Nullable | Notes |
|---|---|---|---|
| source_document_id | bigInteger | no | FK → source_documents |
| record_type | string | no | Accepted values: `organization`, `position`, `project`, `accomplishment`, `tag`, `person`, `link` |
| payload | json | no | The draft's would-be field values, shape depends on record_type |
| status | string | no | Accepted values: `pending`, `confirmed`, `rejected`, `merged`. Default `pending` |
| match_record_type | string | yes | Eloquent model class name or short type key (`tag`, `person`), set when a matching catalog record exists |
| match_record_id | bigInteger | yes | ID of the matched record |

**Relationships.** `belongsTo` source_document.

**Cascade.** `source_document_id` → `cascade`. Deleting the source document discards its drafts.

**Indexes.** Compound index on `(source_document_id, status)` for fast review-queue lookups. Index on `record_type`.

**Two distinct uses of this table.** Entity drafts (`organization`/`position`/`project`/`accomplishment`) capture the AI's would-be records; their payloads are full field maps and confirmation creates real catalog rows. Review records (`tag`/`person`/`link`) capture extracted name/URL mentions and exist purely as the review surface for the wizard's pre-entity-confirmation steps. The two record categories share a table because they share the same lifecycle (status transitions, document scoping, derivation/confirmation/rejection) and Laravel-relationship machinery — but they're conceptually separate. Queries that target one category must filter by `record_type`.

**Entity drafts** carry the full payload shape the AI emitted, including nested arrays:
- `tags`: array of `{name, category?}` objects
- `collaborators`: array of `{name, role?}` objects
- `links`: array of `{url, type?, title?, description?, is_personal_appearance?, date?}` objects

The extracted data is immutable. Review never writes to entity-draft payloads — review decisions live on the review-record rows below. At confirmation time, `attachNestedTags` / `attachNestedCollaborators` walk the nested arrays and resolve names against the catalog via `TagResolver::preview` / `PersonResolver::preview` (read-only — no auto-create). Names with no catalog match are skipped, which is how the wizard's tag/person review steps enforce the user's decisions: a rejected name simply doesn't exist in the catalog by the time entity drafts confirm.

**Review records** carry a thinner payload focused on the unique entry the user is deciding on:
- `tag`: `{extracted_name, category?, catalog_tag_created_by_review?}`
- `person`: `{extracted_name}`
- `link`: `{url, type?, title?, description?, is_personal_appearance?, date?}`

The `catalog_tag_created_by_review` flag on tag review records tracks whether an accept action created the catalog tag the record points at (vs. attaching to a pre-existing one). Reject uses this flag to know whether to delete the catalog tag — review only undoes its own mutations, never tags the user manually curated.

Review records are derived by `ReviewRecordExtractor` (services doc) from the nested arrays on entity drafts in the same document. Tag and person records pre-compute matches against the catalog: matched records land as `status='confirmed'` with `match_record_id` set (no decision to make — already in catalog); unmatched land as `status='pending'` for the wizard to surface. Link review records always land as `status='pending'` regardless of URL existence — links are decorative in the wizard MVP and reviewed per-entity-draft in step 3+.

**Notes.** Drafts deliberately live in their own table rather than as soft-statused real records. This keeps the rest of the app simple — every existing query against organizations/positions/projects/accomplishments continues to work without filtering for "real vs draft" status. The trade-off is a small amount of conversion logic at confirmation time (read draft payload → create real record).

The match fields use a polymorphic-style pair (`match_record_type` + `match_record_id`) rather than a formal Laravel `morphTo` relationship. We only need read access from app code, and the formal morph adds query overhead we don't need.

#### `ai_usage_events`

Records every AI API call: which provider, what operation, how many tokens, what it cost. Lets us answer "how much have I spent this month" and "is text extraction or PDF extraction more expensive per document" without wiring up the AI provider's billing API.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| provider | string | no | Provider identifier — currently `claude`, future providers add their own names |
| model | string | no | Model identifier (e.g., `claude-sonnet-4-6`) |
| operation | string | no | Accepted values: `extract_text`, `extract_pdf`, `synthesize`, `summarize_title`, `count_tokens`, `health_check` |
| source_document_id | bigInteger | yes | FK → source_documents. Null for operations not tied to a specific document (health checks, etc.) |
| input_tokens | unsignedInteger | no | Default `0` |
| output_tokens | unsignedInteger | no | Default `0` |
| cost_cents | unsignedBigInteger | no | Cost in cents per the Money helper convention. Default `0` |
| success | boolean | no | Default `true`. Set to `false` for failed calls, with `error_message` populated |
| error_message | text | yes | Failure detail when `success = false` |

**Relationships.** `belongsTo` source_document.

**Cascade.** `source_document_id` → `set null`. Usage records survive the deletion of the source document they were tied to — we want to retain cost telemetry even if the underlying document is gone.

**Indexes.** Index on `provider`, `operation`, and `created_at` for typical reporting queries ("usage this week," "cost by operation type").

**Notes.** `cost_cents` is computed at call time from `input_tokens × input_rate + output_tokens × output_rate`. Provider rates are configured in `config/services.php` (`extraction.input_cost_per_mtok_cents` and `extraction.output_cost_per_mtok_cents`). When provider pricing changes, only new records reflect the new rates — historical records stay accurate to what the call actually cost.

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

### Resume generation

The tables that support the resume generation flow — capturing job listings, curating catalog entries for relevance, generating drafts, and producing formatted output files.

#### `job_listings`

Entry point to the resume generation flow. A job listing is a child of an organization (typically type `prospect`, though applying to a former employer is valid). Stores the raw pasted listing text and, optionally, AI-extracted structured data.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| organization_id | bigInteger | no | FK → organizations |
| role_title | string | no | |
| body | text | no | The raw pasted listing text, preserved verbatim |
| structured_data | json | yes | AI-extracted fields (requirements, nice-to-haves, responsibilities, etc.). Shape will evolve during dogfooding — JSON avoids premature column commitments |
| source_url | string | yes | Where the listing was found |
| location | string | yes | Free text — "Remote", "NYC (hybrid)", "San Francisco, CA" |
| compensation_range | string | yes | Free text — "$120-150k", "competitive", "$80/hr". Free text rather than structured money fields because listing formats vary wildly and this data is for AI context, not arithmetic |
| date_posted | date | yes | |
| status | string | no | Accepted values: `active`, `closed`. Default `active` |

**Relationships.** `belongsTo` organization. `hasMany` resume_drafts.

**Cascade.** `organization_id` → `cascade`.

**Soft deletes.** Yes.

#### `resume_drafts`

One draft per resume generation attempt against a job listing. The `status` column drives the three-step wizard flow: select catalog entries → generate/edit draft → format final document. A listing can have multiple drafts over time (user wants a different angle, or re-generates after updating their catalog).

| Column | Type | Nullable | Notes |
|---|---|---|---|
| job_listing_id | bigInteger | no | FK → job_listings |
| generated_content | text | yes | AI-generated markdown. Immutable once written — this is the "original" for revert purposes. Null during the `selecting` phase |
| user_content | text | yes | Starts as a copy of `generated_content`, user edits this. Null until generation completes |
| format_preference | string | yes | Accepted values: `docx`, `pdf`. Default null |
| status | string | no | Accepted values: `selecting`, `drafting`, `editing`, `approved`, `formatted`. Default `selecting` |

**Relationships.** `belongsTo` job_listing. `hasMany` resume_selections. `hasMany` resume_artifacts.

**Cascade.** `job_listing_id` → `cascade`.

**Soft deletes.** Yes.

**Notes.** Status transitions enforce the wizard flow: `selecting` → user reviewing AI-suggested catalog entries (step 1); `drafting` → selections confirmed, AI generation in progress; `editing` → draft generated, user reviewing/editing (step 2); `approved` → ready for formatting; `formatted` → final document generated (step 3 complete).

Content is stored as two columns (`generated_content` and `user_content`) rather than a revisions table. MVP only needs "revert to original" — a full `resume_revisions` table is additive if users want undo history later.

#### `resume_selections`

Tracks which catalog entries the AI suggested for a resume and whether the user chose to include each one. This is the step 1 output — the curated set of experience that feeds into draft generation.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| resume_draft_id | bigInteger | no | FK → resume_drafts |
| selectable_type | string | no | Polymorphic model class: Position, Project, Accomplishment, CareerTheme, Tag, Link |
| selectable_id | bigInteger | no | ID of the catalog record |
| selected | boolean | no | Default `true`. True = include in resume, false = user excluded it |
| ai_reasoning | text | yes | Why the AI suggested this entry — shown in the review UI to help the user decide |
| display_order | integer | no | Default `0`. Controls rendering order within each type group |

**Relationships.** `belongsTo` resume_draft. `morphTo` selectable.

**Cascade.** `resume_draft_id` → `cascade`.

**Soft deletes.** No — lightweight decision records, similar to `accomplishment_collaborators`.

**Indexes.** Compound index on `(resume_draft_id, selectable_type)` for fast grouped lookups. Compound index on `(selectable_type, selectable_id)` for the reverse query ("which resumes used this accomplishment?").

**Notes.** The AI suggests entries across six entity types: positions (which jobs to include), projects and accomplishments (the evidence under each job), career themes (the narrative spine), tags (skills to highlight), and links where `is_personal_appearance = true` (portfolio items to feature). The user toggles `selected` on each. At draft generation time, only `selected = true` entries feed into the prompt. The review UI groups selections by position, with projects and accomplishments nested under their parent position via existing relationships — that grouping is derived at render time, not stored here.

#### `resume_artifacts`

Immutable formatted output files. Each artifact is a point-in-time snapshot of a rendered resume.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| resume_draft_id | bigInteger | no | FK → resume_drafts |
| file_path | string | no | Relative storage path |
| file_format | string | no | Accepted values: `docx`, `pdf` |
| file_size_bytes | unsignedInteger | yes | Populated after generation |

**Relationships.** `belongsTo` resume_draft.

**Cascade.** `resume_draft_id` → `cascade`.

**Soft deletes.** Yes — generated resumes are immutable artifacts per the mission doc.

**Notes.** A draft can have multiple artifacts (user generates a .docx, then also wants a .pdf, or re-generates after editing). Each is a point-in-time snapshot. The `user_content` that produced it is on the parent `resume_drafts` row.

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
| `extracted_records.source_document_id` → source_documents | cascade |
| `ai_usage_events.source_document_id` → source_documents | set null |
| `ai_usage_events.resume_draft_id` → resume_drafts | set null |
| `job_listings.organization_id` → organizations | cascade |
| `resume_drafts.job_listing_id` → job_listings | cascade |
| `resume_selections.resume_draft_id` → resume_drafts | cascade |
| `resume_artifacts.resume_draft_id` → resume_drafts | cascade |

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
- `applications` (resume builder — application tracking milestone)
- `references`, `certifications`, `education`