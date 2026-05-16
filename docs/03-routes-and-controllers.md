# Routes and Controllers

All routes live in `routes/web.php`. No API routes yet — the app is server-rendered Blade.

## Current entities

| Entity | Top-level routes | Create-in-context routes |
|---|---|---|
| Organization | full resource | — |
| Position | resource minus `index` and `create` | `organizations/{org}/positions/create` |
| Project | resource minus `index` and `create` | three: under organization, under position, under parent project |
| Accomplishment | resource minus `index` and `create` | two: under project, under position |
| Link | partial resource (`store`, `edit`, `update`, `destroy`) | four: under organization, project, position, accomplishment |
| Tag | resource minus `show` | — |
| TagAlias | `store`, `destroy` only | nested under tag: `tags/{tag}/aliases` |
| Person | full resource | — |

Each entity has its own controller in `app/Http/Controllers/` and a corresponding `{Entity}CrudTest` in `tests/Feature/`.

## Source documents

The AI extraction pipeline runs on source documents. `SourceDocumentController` handles the full lifecycle:

| Route | Method | Purpose |
|---|---|---|
| `/` | GET | `CareerInputController@index` — home page form for pasting text or uploading a file |
| `source-documents` | POST | Create document, generate title via AI, redirect to preview |
| `source-documents/{id}/preview` | GET | Cost preview before running extraction |
| `source-documents/{id}/extract` | POST | Run extraction synchronously, redirect to review queue |
| `source-documents/{id}` | GET | Show page — title, body, draft counts, link to review |
| `source-documents/{id}/file` | GET | Serve uploaded file (PDFs); `?download=1` forces attachment |
| `source-documents/{id}` | DELETE | Cancel a pending submission (no drafts yet) |

The flow is two-step: store-then-extract. The store request creates the document and generates a title; the user reviews the cost estimate on the preview page, then explicitly confirms extraction. This avoids surprise API costs and gives the user a chance to back out.

Synchronous extraction with a loading overlay is the MVP approach. When extraction times become a problem, switch to a real queue driver (see [AI Development Notes — Cache, Queue, and Session Drivers](05-ai-development-notes.md#cache-queue-and-session-drivers)).

## Draft review queue

The review flow is a multi-step wizard. Step 1 is tag review (a dedicated list page); step 2 will be person review (chunk 4c, not yet built); step 3+ walks entity drafts (organizations, positions, projects, accomplishments) one at a time. The index route routes the user to the appropriate step.

### Tag review (wizard step 1)

`TagReviewController` handles the dedicated tag review page where extracted tag names get accepted, rejected, or aliased to existing catalog tags:

| Route | Method | Purpose |
|---|---|---|
| `source-documents/{doc}/review/tags` | GET | `show` — render all tag review records for the document, grouped by category. Redirects to the review index when the document has zero tag review records. |
| `source-documents/{doc}/review/tags/{record}/accept` | POST | `accept` — find-or-create a catalog tag, set status to `confirmed`. Returns `{ok: true, catalog_tag_name}` on success. |
| `source-documents/{doc}/review/tags/{record}/reject` | POST | `reject` — set status to `rejected`. If the record's previous accept created a catalog tag (tracked via `catalog_tag_created_by_review` payload flag), delete it. |
| `source-documents/{doc}/review/tags/{record}/alias` | POST | `alias` — body: `{target_tag_id}`. Create a `tag_aliases` row from extracted_name → target tag, set status to `merged`. |

**Idempotent state transitions.** Each action handles any starting state, reverting prior mutations before applying the new state. The user can accept → change mind → reject → change mind → accept again; each transition cleans up the previous decision via `revertPriorDecision`.

**JSON contract for actions.** All three action endpoints return JSON exclusively: `{ok: true}` (plus `catalog_tag_name` on accept) on success, `{error: '...'}` with a 4xx/5xx status on failure. The JS client treats responses uniformly — no partial HTML, no 204s. Show returns Blade.

**Known limitation: dependent record invalidation on reject.** If a tag review record's accept created a catalog tag, and another tag review record then aliased to that catalog tag, rejecting the first record cascade-deletes the alias row (via the `tag_aliases.tag_id` foreign-key cascade). The second record's UI state becomes inconsistent (status=merged, match_record_id points to deleted tag). Recovery: refresh the page, re-decide. Not blocking for MVP — edge case requires aliasing to a review-created tag.

### Entity-draft review (wizard step 3+)

`DraftReviewController` walks the entity drafts produced by extraction:

| Route | Method | Purpose |
|---|---|---|
| `source-documents/{doc}/review` | GET | `index` — routes to wizard step 1 if pending tag records exist; else first pending entity draft; else fallback to show page |
| `source-documents/{doc}/review/{draft}` | GET | `show` — render the draft with form inputs (pending) or read-only display (other statuses); also computes duplicate candidates via `DuplicateDetector` and passes them to the view |
| `source-documents/{doc}/review/{draft}/confirm` | POST | `confirm` — merge form data into payload, create real record, navigate to next |
| `source-documents/{doc}/review/{draft}/reject` | POST | `reject` — cascade rejection to dependent drafts, navigate to next |
| `source-documents/{doc}/review/{draft}/restore` | POST | `restore` — flip a rejected draft back to pending |

The entity-draft routes are wrapped in the `RequireTagReviewComplete` middleware. Deep-linking past tag review while pending tag records exist redirects back to step 1. Tag review pages and action endpoints themselves are not gated.

`DraftMergeController` handles the merge flow when duplicate detection has surfaced one or more candidates:

| Route | Method | Purpose |
|---|---|---|
| `source-documents/{doc}/review/{draft}/merge` | GET | `show` — candidate picker (when multiple matches) or side-by-side editor (when one match resolved). The chosen candidate is passed via `?candidate_id=` query param |
| `source-documents/{doc}/review/{draft}/merge/synthesize` | POST | `synthesize` — JSON endpoint, takes `field` (payload key) plus existing and draft values, returns the synthesized string. Logs an `AiUsageEvent` via the existing tracker |
| `source-documents/{doc}/review/{draft}/merge` | POST | `store` — execute the merge with chosen per-field values, mark draft `merged`, navigate to next draft in queue |

**Queue ordering.** Entity drafts are walked type-first: organizations → positions → projects → accomplishments. This matches the dependency structure — by the time the user reaches an accomplishment, its supporting parents have already been reviewed and (presumably) confirmed. The queue scope is entity types only; tag/person/link review records are excluded.

**All drafts browsable.** The review page shows every entity draft regardless of status — pending, rejected, confirmed, or merged. Status badges indicate which is which, and the action bar branches per status (confirm/reject for pending, restore for rejected, status note for others). Nothing is hidden; users can navigate to any draft they reviewed.

**Form-merge confirmation.** The pending draft's display is a `<form>` whose Confirm button submits to the confirm endpoint. The user's edits get merged into the payload and saved before the confirmer runs. This serves two purposes: the user can fix fields the AI omitted (which is the only way to provide missing required fields before confirmation), and edits persist even when confirmation fails (e.g., parent not resolved) so they aren't lost.

**Cascade rejection.** Rejecting an organization also rejects positions/projects/accomplishments that reference it. The dependency walk happens in `ExtractedRecord::findDependents()`. The view shows a confirmation modal listing how many drafts will be affected when there's at least one dependent.

**Detection-on-load.** Duplicate candidates are computed in `DraftReviewController::show()` via `DuplicateDetector::findCandidates()`. When the result is non-empty, the show view renders a "Merge into..." affordance in the action bar next to Confirm and Reject, linking to the merge controller. This keeps the merge offer visible before the user clicks anything rather than intercepting a confirm with a surprise redirect.

**Two-step merge UI.** The merge `show` route serves either a candidate picker or the side-by-side editor depending on whether `?candidate_id=` is set in the URL. When detection returns multiple candidates, the user lands on the picker first, selects one, and the picker links them to the same route with `candidate_id` populated — which then renders the editor. When detection returns exactly one candidate, the action bar's "Merge into..." link goes directly to the editor URL (skipping the picker), since there's nothing to pick.

**Per-field synthesis is on-demand.** The editor renders three options for each textarea field (keep existing, use draft, synthesize). The synthesized value is fetched only when the user clicks the synthesize button, so we don't pre-pay for synthesis on fields the user might not pick the synthesized version for. The endpoint accepts the field name plus both source strings and returns the combined text plus a fresh `AiUsageEvent` id for telemetry.

**Confirmed earlier drafts act as candidates.** A draft that's confirmed earlier in the same source-document review session lives in the real catalog by the time a later draft loads. Detection runs against the catalog, so a position draft whose org has already been confirmed sees the new org record naturally — no special "this draft was just imported" handling is needed. The merge UI treats it like any other existing record.

## Middleware

### `RequireTagReviewComplete`

Applied to entity-draft routes (`source-documents.review.show/confirm/reject/restore/merge.*`). Reads the `{sourceDocument}` route binding, checks for any tag review records with `status='pending'`, and redirects to `source-documents.review.tags.show` if found. Pass-through when no pending tags or when no source document is in the route.

The middleware only considers tag records on the current document and only the `status='pending'` ones. Records the user has already decided (confirmed/rejected/merged) don't gate, and records on other documents are irrelevant. Person review will get its own symmetric middleware when chunk 4c lands.

## Form requests

Each entity that accepts writes has three classes in `app/Http/Requests/`:

- `{Entity}Rules` — shared validation rules and input normalization. Holds accepted-value constants used by both controllers and views.
- `Store{Entity}Request` — delegates to `{Entity}Rules::rules()` and `::normalize()`
- `Update{Entity}Request` — same

`prepareForValidation()` runs `Rules::normalize()` to trim strings, convert empty strings to null, strip thousands separators from numeric inputs, and any per-entity cleanup (e.g., clearing `reason_for_leaving_notes` when the reason is empty).

The draft review queue does **not** use form requests because the draft is staged data, not yet a real record. The controller normalizes form input inline (trim, empty-to-null) and passes the merged payload to the `DraftConfirmer` service. Model-layer invariants and DB constraints are the validation layer; their failures become user-facing flash messages via `DraftConfirmationException`.

## Create-in-context pattern

Entities created under a parent use a nested URL that pre-fills the parent's foreign keys, avoiding a parent-select dropdown in the form. The form's `$entity` variable comes pre-populated from the controller; the parent IDs render as hidden inputs.

Route names follow `{entity}.create{Context}` — e.g., `projects.createForPosition`, `projects.createSubProject`.

## Links: polymorphic ownership

Links attach to multiple parent entity types (organizations, projects, positions, accomplishments — and eventually people, when the Person UI slice lands). Rather than a separate controller per parent or a fully nested resource per parent, `LinkController` exposes:

- One `createFor*` route per parent type, mirroring the create-in-context pattern.
- A single polymorphic `POST /links` store endpoint. The form sends `linkable_type` as a short alias string (`'organization'`, `'project'`, `'position'`, `'accomplishment'`) and `linkable_id` as the parent's primary key. The controller maps the alias to a model class via `LinkController::LINKABLE_MAP` and loads the parent via `findOrFail`.
- A flat `links/{link}` resource for `edit`, `update`, and `destroy`. The link's existing parent is recovered from its polymorphic `linkable` relationship rather than from the URL, which keeps the route table flat as parent types are added.

**Edit deliberately cannot reparent a link.** `UpdateLinkRequest::rules()` omits `linkable_type` and `linkable_id`, so any tampered values get stripped before the update runs. The edit form template doesn't render the hidden parent inputs at all — defense in depth on top of the form-request filtering.

**Type-conditional URL/title rules.** Most link types require a URL; `internal_doc` requires a title instead and allows the URL to be null. The form layer enforces this via `required_unless:type,internal_doc` and `required_if:type,internal_doc`; the model layer's `validateInvariants()` is the safety net.

**Per-linkable type filtering.** The dropdown options on each create form are scoped via `LinkRules::TYPES_BY_LINKABLE` so context-inappropriate types aren't offered (e.g., `slack` doesn't appear on an accomplishment, `repo` doesn't appear on an organization). The DB still accepts any value from `Link::TYPES` regardless of parent — this is purely a UI affordance. On the edit page, if a link's current type is somehow outside its parent's applicable list (e.g., from AI extraction or direct DB manipulation), the controller appends it to the options so the user sees the current value selected.

**Links have no show page or index.** They display inline on their parent's show page via the `links._section` partial. Adding a new parent type that accepts links touches a handful of places: add a `createFor*` controller method, register the route, add entries to `LinkController::LINKABLE_MAP`, the `showUrlFor`/`aliasFor`/`viewContext` helpers, and `LinkRules::TYPES_BY_LINKABLE`, then add the partial's `match` arm and an `@include('links._section', ['linkable' => $parent])` to the new parent's show template. The People slice will exercise this path.

## Tags and the picker

Tags are flat reference data shared across taggable entities. The model is simpler than links: a single top-level `Tag` resource (no show page — the edit page handles view and management together), plus `TagAlias` nested under tags for alternate spellings. Tags have no soft deletes; the DB cascade on `taggables.tag_id` and `tag_aliases.tag_id` cleans dependent rows automatically on hard delete.

**Picker is reusable, auto-mounting.** The `tags._picker` partial is included on any parent form that supports tagging (`@include('tags._picker', ['entity' => $parent])`). The JS module at `resources/js/tag-picker.js` finds every `[data-tag-picker]` on `DOMContentLoaded` and wires it up — no manual init per form. Server-rendered chips with hidden `tag_ids[]` inputs hold the initial selection; the JS adds and removes chips, syncing the hidden inputs as the user goes. If JS fails to load, the user still sees their current tags and can submit unchanged — graceful degradation.

**The `tag_ids` flow on parent controllers.** Each parent's Rules class ends its `rules()` array with `+ TagRules::syncRules()`, adding `tag_ids` and `tag_ids.*` validation. The controller's `store` and `update` use `$request->safe()->except('tag_ids')` for mass-assignment of entity attributes, then call `$entity->tags()->sync($request->input('tag_ids', []))` to apply the picker's selection. Sync semantics are intentional: an empty `tag_ids` array (or a missing key) detaches every tag.

**Search endpoint ranks four tiers in PHP.** `GET /tags/search?q=…` returns up to 5 results ranked by match quality: (1) name prefix, (2) alias prefix, (3) name substring, (4) alias substring — alphabetical within each tier. Ranking is done in PHP after a single SQL query because a four-tier `CASE WHEN` is gnarly and the candidate set is tiny at MVP scale. The endpoint surfaces a `matched_alias` field on alias-match results so the picker can show "(matched: postgres)" — transparency for the user about why PostgreSQL matched their "postgres" query.

**Source-document tagging is AI-only.** Source documents are schema-level taggable (the polymorphic join supports it) but are deliberately excluded from the picker. AI extraction populates source-document tags as part of the pipeline; surfacing the same picker there would create competing inputs without clear semantics. The dedicated review screen for AI-suggested tags lands with the AI-pipeline-extension slice. See the schema doc for the contract.

**Cross-table invariant: tag names and aliases share a namespace.** A canonical tag name can't collide with any existing alias, and vice versa. Enforced at the form layer (closure rules in `TagRules` and `TagAliasRules` that produce friendly errors) and at the model layer (`validateInvariants()` on both `Tag` and `TagAlias`).

## People and the collaborator picker

People are managers, collaborators, mentors, and other individuals the user has worked with. Modeled once at `app/Models/Person.php` and attached to positions, projects, and accomplishments via three identically-shaped pivot tables (`position_collaborators`, `project_collaborators`, `accomplishment_collaborators`), each with a free-text `role_on_*` column.

**Schema convergence: no dedicated manager FK.** An earlier iteration had `positions.reports_to_person_id` as a dedicated foreign key column. That diverged from the accomplishment-collaborator pattern and made the AI extraction surface awkward (two different shapes for "who was involved"). The convergence migration dropped the FK and unified everyone on the pivot-with-role pattern: a "manager" is just a collaborator with `role_on_position = "Manager"`. See `docs/01-database-schema.md` for the full rationale.

**Full resource with show page.** Unlike tags (no show page, edit doubles as view), people have enough relationship surface — collaborator history across positions, projects, and accomplishments — to justify their own show page. The show page surfaces three sections, each rendered only if non-empty: positions, projects, accomplishments. Each row links to the parent record and displays the role from the pivot.

**Index page groups by current organization.** People with a `current_organization_id` cluster under their org's name (linked to the org's show page); people without one fall into an "Unaffiliated" bucket rendered last regardless of alphabet. Within each group, alphabetical by name.

**Picker is reusable across three parent forms.** The `people._picker` partial is included on position, project, and accomplishment forms. Each chip carries a hidden `person_id` input plus a visible free-text `role` input, submitted as `collaborators[i][person_id]` and `collaborators[i][role]`. Indices are monotonic and can be sparse — Laravel's validator handles sparse arrays via the `collaborators.*` wildcard.

**Role suggestions via HTML datalist.** Common values ("Manager", "Direct report", "Peer", "Mentor", "Mentee", "Client", "Vendor") are presented via a `<datalist>` for the role input on each chip. Free text but with a soft nudge toward consistency. The datalist values aren't enforced — the user can type anything.

**Shared helpers on `PersonRules`.** Two static methods power the per-entity wiring: `collaboratorSyncRules()` returns the validation rules (spread into each parent's Rules class via `+ PersonRules::collaboratorSyncRules()`), and `buildCollaboratorSyncData($collaborators, $roleColumn)` transforms the form payload into Eloquent's `sync()`-friendly shape with the appropriate role column. Same single-source-of-truth pattern as `TagRules::syncRules()`.

**Sync semantics — destructive replacement.** The picker submits exactly the chips currently displayed; `sync()` replaces the entire set. Empty array detaches all. Empty role strings normalize to null at the form layer so the DB stores a consistent "no role specified" sentinel.

**Soft-delete preserves collaborator pivots.** Pivot rows in the three collaborator tables are NOT touched by Person's soft-delete. The relationships still reference the soft-deleted person; restoring the person brings back the full history. Force-delete (DB cascade) is the only way to wipe pivots.

**Search endpoint mirrors tags but simpler.** `GET /people/search?q=…` returns up to 5 ranked results (tier 1: name prefix, tier 2: name substring). Response includes `current_title` and `current_organization_name` for picker dropdown disambiguation. No alias machinery — people don't have aliases.

## Destroy redirects

`destroy()` soft-deletes and redirects to a contextually appropriate parent: organizations to the index, positions to their organization, projects to their parent project / position / organization in that priority order.

There's no UI for restoring soft-deleted records yet; recovery goes through the database directly.

## Test footguns

Two patterns worth knowing about, both documented in the relevant test files:

**Form-submitted IDs arrive as strings.** Tests that mimic the form path should pass IDs as `(string) $entity->id` to catch type-comparison bugs in validators.

**Eloquent applies casts on read after a DB load, not on direct assignment.** Tests that create then immediately assert on a cast attribute should call `$model->refresh()` first.