# Routes and Controllers

All routes live in `routes/web.php`. No API routes yet — the app is server-rendered Blade.

## Current entities

| Entity | Top-level routes | Create-in-context routes |
|---|---|---|
| Organization | full resource | — |
| Position | resource minus `index` and `create` | `organizations/{org}/positions/create` |
| Project | resource minus `index` and `create` | three: under organization, under position, under parent project |
| Accomplishment | resource minus `index` and `create` | two: under project, under position |

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

`DraftReviewController` walks the user through the drafts produced by extraction:

| Route | Method | Purpose |
|---|---|---|
| `source-documents/{doc}/review` | GET | `index` — redirects to first pending draft, or first draft overall if none pending |
| `source-documents/{doc}/review/{draft}` | GET | `show` — render the draft with form inputs (pending) or read-only display (other statuses); also computes duplicate candidates via `DuplicateDetector` and passes them to the view |
| `source-documents/{doc}/review/{draft}/confirm` | POST | `confirm` — merge form data into payload, create real record, navigate to next |
| `source-documents/{doc}/review/{draft}/reject` | POST | `reject` — cascade rejection to dependent drafts, navigate to next |
| `source-documents/{doc}/review/{draft}/restore` | POST | `restore` — flip a rejected draft back to pending |

`DraftMergeController` handles the merge flow when duplicate detection has surfaced one or more candidates:

| Route | Method | Purpose |
|---|---|---|
| `source-documents/{doc}/review/{draft}/merge` | GET | `show` — candidate picker (when multiple matches) or side-by-side editor (when one match resolved). The chosen candidate is passed via `?candidate_id=` query param |
| `source-documents/{doc}/review/{draft}/merge/synthesize` | POST | `synthesize` — JSON endpoint, takes `field` (payload key) plus existing and draft values, returns the synthesized string. Logs an `AiUsageEvent` via the existing tracker |
| `source-documents/{doc}/review/{draft}/merge` | POST | `store` — execute the merge with chosen per-field values, mark draft `merged`, navigate to next draft in queue |

**Queue ordering.** Drafts are walked type-first: organizations → positions → projects → accomplishments. This matches the dependency structure — by the time the user reaches an accomplishment, its supporting parents have already been reviewed and (presumably) confirmed.

**All drafts browsable.** The review page shows every draft regardless of status — pending, rejected, confirmed, or merged. Status badges indicate which is which, and the action bar branches per status (confirm/reject for pending, restore for rejected, status note for others). Nothing is hidden; users can navigate to any draft they reviewed.

**Form-merge confirmation.** The pending draft's display is a `<form>` whose Confirm button submits to the confirm endpoint. The user's edits get merged into the payload and saved before the confirmer runs. This serves two purposes: the user can fix fields the AI omitted (which is the only way to provide missing required fields before confirmation), and edits persist even when confirmation fails (e.g., parent not resolved) so they aren't lost.

**Cascade rejection.** Rejecting an organization also rejects positions/projects/accomplishments that reference it. The dependency walk happens in `ExtractedRecord::findDependents()`. The view shows a confirmation modal listing how many drafts will be affected when there's at least one dependent.

**Detection-on-load.** Duplicate candidates are computed in `DraftReviewController::show()` via `DuplicateDetector::findCandidates()`. When the result is non-empty, the show view renders a "Merge into..." affordance in the action bar next to Confirm and Reject, linking to the merge controller. This keeps the merge offer visible before the user clicks anything rather than intercepting a confirm with a surprise redirect.

**Two-step merge UI.** The merge `show` route serves either a candidate picker or the side-by-side editor depending on whether `?candidate_id=` is set in the URL. When detection returns multiple candidates, the user lands on the picker first, selects one, and the picker links them to the same route with `candidate_id` populated — which then renders the editor. When detection returns exactly one candidate, the action bar's "Merge into..." link goes directly to the editor URL (skipping the picker), since there's nothing to pick.

**Per-field synthesis is on-demand.** The editor renders three options for each textarea field (keep existing, use draft, synthesize). The synthesized value is fetched only when the user clicks the synthesize button, so we don't pre-pay for synthesis on fields the user might not pick the synthesized version for. The endpoint accepts the field name plus both source strings and returns the combined text plus a fresh `AiUsageEvent` id for telemetry.

**Confirmed earlier drafts act as candidates.** A draft that's confirmed earlier in the same source-document review session lives in the real catalog by the time a later draft loads. Detection runs against the catalog, so a position draft whose org has already been confirmed sees the new org record naturally — no special "this draft was just imported" handling is needed. The merge UI treats it like any other existing record.

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

## Destroy redirects

`destroy()` soft-deletes and redirects to a contextually appropriate parent: organizations to the index, positions to their organization, projects to their parent project / position / organization in that priority order.

There's no UI for restoring soft-deleted records yet; recovery goes through the database directly.

## Test footguns

Two patterns worth knowing about, both documented in the relevant test files:

**Form-submitted IDs arrive as strings.** Tests that mimic the form path should pass IDs as `(string) $entity->id` to catch type-comparison bugs in validators.

**Eloquent applies casts on read after a DB load, not on direct assignment.** Tests that create then immediately assert on a cast attribute should call `$model->refresh()` first.