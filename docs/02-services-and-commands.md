# Services and Commands

## Service classes

### `App\Support\Money`

Static helper for converting between integer cents (how money is stored) and human-readable strings (how it's shown and entered).

```php
Money::format(?int $cents): ?string
Money::parse(?string $input): ?int
```

`format()` is called from views. `parse()` is called from form requests in `prepareForValidation()`. Models do not call Money — they expose raw integer cents and apply no conversion.

See [AI Development Notes](05-ai-development-notes.md#money-storage) for the rationale on integer cents.

### `App\Services\AiUsageTracker`

Records every AI API call to the `ai_usage_events` table. Captures the operation kind (`extract_drafts`, `generate_title`, `synthesize_descriptions`, etc.), input/output token counts, dollar cost, success/failure, and optional source document reference.

Failed operations (timeouts, JSON parse errors, rate limits) get logged with `success=false` and a truncated error message. This means a single source of truth for cost tracking and debugging — `ai_usage_events` shows everything, not just what worked.

The tracker is invoked from the extraction provider and any other service that calls Anthropic's API. Pricing constants for the current model live in the tracker itself; update them in one place when the model or pricing changes.

## Extraction pipeline

Turning a `source_document` into draft records the user can review.

### `App\Services\Extraction\ExtractionProvider` (interface)

The provider interface. Two implementations: `ClaudeExtractionProvider` (production, calls Anthropic) and `FakeExtractionProvider` (tests, returns canned responses).

Methods: `extract()` for the main pipeline, `summarizeTitle()` for naming pasted text, and `synthesize()` for combining two descriptions during merge. Each returns a typed result object.

### `App\Services\Extraction\ClaudeExtractionProvider`

The production extraction provider. Calls `claude-sonnet-4-6` via the Anthropic API. Sends the source document body (or base64-encoded PDF) along with a system prompt describing the schema. Parses the JSON array response into `DraftRecord` value objects.

Key behaviors:
- Strict prompt requiring specific JSON shape (record type + payload per record).
- Per-record-type field lists in the prompt match the field schemas in `DraftFieldSchema`. When the schema changes, both update together.
- Soft-fails on errors: catches parse failures and API errors, logs to `AiUsageTracker` with `success=false`, and bubbles up an `ExtractionException` with a user-friendly message.
- Pricing constants for the current model live in `AiUsageTracker` (not here) so they're managed centrally.

### `App\Services\Extraction\FakeExtractionProvider`

Test double. Returns deterministic canned data. Used in feature and unit tests to avoid actual API calls.

Bound via the service container in tests:
```php
$this->app->bind(ExtractionProvider::class, FakeExtractionProvider::class);
```

### Value objects

- `DraftRecord` — `{record_type, payload}` produced by extraction
- `ExtractionResult` — wrapper around a Collection of DraftRecord plus token usage
- `SummaryResult` — generated title plus token usage
- `SynthesisResult` — merged description plus token usage
- `ExtractionException` — thrown for any provider failure with a user-facing message

### `App\Services\Extraction\ReviewRecordExtractor`

Derives top-level `tag`, `person`, and `link` review records from the nested arrays on a source document's pending entity drafts. Single public method `extract(SourceDocument $document): int` returning the count of review records created.

Walks the document's pending entity drafts, dedupes nested entries (case-insensitive name for tags and people, exact URL for links), pre-computes catalog matches via `TagResolver::preview` and `PersonResolver::preview`, and persists one review record per unique entry.

**Auto-confirm at derivation.** Matched tag and person records land as `status='confirmed'` — the catalog is authoritative, no decision needed. Unmatched land as `status='pending'` for the wizard's review UI to surface. Links always land as `pending` (link review is per-entity-draft, not a wizard step).

**Idempotent.** Re-running on a document that already has any tag/person/link review records is a no-op (returns 0). Refresh via the artisan command's `--force` flag, which deletes pending review records first.

**Wired into `SourceDocumentController::extract`** so derivation runs immediately after entity drafts persist — by the time the user lands on the review page, all review records exist.

## Draft confirmation

Turning reviewed drafts into real catalog records.

### `App\Services\Drafts\DraftConfirmer`

Service that converts a pending `ExtractedRecord` into a real `Organization`, `Position`, `Project`, or `Accomplishment`. Single public method `confirm(ExtractedRecord $draft): Model`.

Internally branches on `record_type` and dispatches to per-type private methods. Each:
1. Pulls relevant fields from `$draft->payload`
2. Resolves parent references (e.g., `organization_name` string) to foreign key IDs via exact-name lookup
3. Filters payload to fillable fields on the target model
4. Creates the model inside a transaction
5. Updates the draft's `status` to `confirmed` and sets `match_record_type`/`match_record_id`

**Parent resolution.** When a draft references a parent by name (positions reference an org, projects reference an org and optionally a position, accomplishments reference a project or position), the confirmer looks up the parent by exact name (case-insensitive). If the parent doesn't exist as a real catalog record yet, confirmation fails with a user-facing message telling the user to confirm the parent first.

This is intentionally exact-match. Fuzzy matching ("Lightning Labs" matches "Lightning Labs Inc"?) is slice 4.5's job — duplicate detection. For now, confirmation is mechanical and predictable.

**Nested attachment uses preview, not resolve.** When entity drafts confirm, `attachNestedTags` and `attachNestedCollaborators` call `TagResolver::preview` / `PersonResolver::preview` (read-only) rather than `resolve()` (find-or-create). Names with no catalog match are skipped, not auto-created. This is the mechanism that enforces the wizard's tag/person review decisions at materialization time: a rejected name is absent from the catalog by the time entity drafts confirm, so attachment skips it naturally. The extracted-data payload is never modified — the audit trail stays intact.

`attachNestedLinks` is symmetric in spirit but creates `Link` rows directly via the parent's morphMany (link review is per-entity-draft, not a separate wizard step). The link's `type` field is validated against `Link::TYPES`; invalid types default to `'other'` since the column is non-nullable.

**Accomplishments are special.** They attach to either a project OR a position, never both. The payload's `project_name` decides which branch. `organization_name` is required for the position-attached branch (positions are identified by org+title and can't be disambiguated without it) but optional for the project-attached branch — if a `project_name` uniquely identifies a project across the catalog, we'll use it directly. If two orgs each have a project with the same name, we error and ask for `organization_name` to disambiguate. This relaxation handles the AI occasionally omitting org_name on accomplishments.

**Error handling.** The confirmer wraps its work in a transaction and catches both `QueryException` (NOT NULL constraint failures from missing required fields) and `InvalidArgumentException` (model-layer invariant guards like "accomplishment must have date or period_start"). Both convert to `DraftConfirmationException` with readable messages. The controller surfaces these as flash messages and stays on the draft.

### `App\Services\Drafts\DraftConfirmationException`

A `RuntimeException` subclass thrown when a draft can't be confirmed. The exception's message is the user-facing flash text — write it accordingly.

### `App\Services\Drafts\DraftFieldSchema`

Static helper that defines the editable field schema per record type. Returns an array keyed by payload field name, with each entry describing:

- `type` — `text`, `textarea`, `date`, `select`, or `number`
- `required` — whether the form must collect this
- `options` — for `select` types, the allowed values
- `label` — human-readable label for the form
- `help` — optional hint text shown below the input

Drives two things: the review page renders form inputs based on the schema, and `DraftReviewController::confirm()` uses the schema's keys to decide which form fields to accept.

The schema is hand-maintained rather than derived from models because payload field names occasionally differ from model column names (e.g., `organization_name` in the payload resolves to `organization_id` on Position). Schema describes the payload shape; the confirmer maps payload to model.

When adding a field to a model, the schema needs to know about it before the form can collect it. Same for changing select options — update both `DraftFieldSchema` and the model's accepted-values constants.

## Draft merge

Turning a draft into edits on an existing record rather than creating a new one. Applies when duplicate detection finds a candidate match the user wants to merge into instead of confirming as new.

### `App\Services\Drafts\DuplicateDetector`

Finds existing catalog records a draft might be a duplicate of. Single public method `findCandidates(ExtractedRecord $draft): Collection`. Returns an empty collection when nothing matches; otherwise returns the matching `Organization`, `Position`, or `Project` models. The controller orders for display — no sort guarantee from the service.

**Match rules per record type:**

- `organization` — case-insensitive substring match against `organizations.name`, in either direction. Both "Lightning" → "Lightning Labs" and "Stripe Inc" → "Stripe" count as candidates.
- `position` — exact case-insensitive title match within the same organization. The draft's `organization_name` must already resolve to an existing org (case-insensitive name lookup); if no parent org is in the catalog yet, the position can't have duplicates and the result is empty.
- `project` — case-insensitive substring match against `projects.name`, scoped to projects belonging to the resolved organization. Same bidirectional logic as organizations. Cross-org matches are deliberately excluded — "Migration" at company A is not a duplicate of "Migration" at company B.
- `accomplishment` — not scoped by this slice. Returns an empty collection. Accomplishments have too much title variance for naïve string matching to be useful; revisit if a clear pattern emerges.

Detection runs on draft load (the review show page), not at confirm time. This surfaces "Merge into..." next to Confirm/Reject before the user clicks anything, instead of intercepting a confirm with a surprise redirect. The cost is one extra small query per draft view.

### `App\Services\Drafts\DraftMerger`

Executes a merge: updates the chosen existing record with the user's selected per-field values, marks the draft as merged, and rewrites parent-name references in pending dependent drafts so they continue to resolve at confirmation time.

Single public method `merge(ExtractedRecord $draft, Model $target, array $fieldChoices): Model`. `$fieldChoices` is keyed by payload field name with values being the final resolved string per field — whatever the user picked or synthesized in the UI. The service doesn't need to know which "side" each value came from, only the resolved value.

The whole merge runs in a single transaction:

1. Collect dependent drafts first — same logic as cascade rejection (`ExtractedRecord::findDependents()`), since the dependency walk reads the draft's pre-merge payload.
2. Update the target record with the chosen field values (filtered to fillable columns on the target model, same shape as `DraftConfirmer`).
3. Mark the draft `status='merged'`, `match_record_type=class, match_record_id=$target->id`.
4. Rewrite each dependent draft's payload: replace the parent-name reference (`organization_name`, `position_title`, or `project_name` depending on what was merged) with the target's canonical name.

The walk in step 1 is delegated to `ExtractedRecord::findDependents()` — the same walk used by cascade rejection. Keeping merge and reject aligned on what counts as a dependency means the two flows can't drift out of sync. The current walk does NOT include `parent_project_name` references (a sub-project draft pointing at this draft as its parent); if that ever changes for cascade rejection it should change for merge in the same commit.

Step 4 is what makes the merge stick across the rest of the queue. Without it, a dependent position draft would still reference the old name ("Lightning Labs") and fail confirmation with "not in your catalog yet" even though the merge resolved it to "Lightning Labs Inc."

### `App\Services\Drafts\DraftMergerException`

A `RuntimeException` subclass thrown when a merge can't complete. Same exception-as-flash-message pattern as `DraftConfirmationException` — write the message for the user, the controller surfaces it and stays on the merge page.

## Defense-in-depth on AI inputs

A pattern that emerged during milestone 4: the AI is non-deterministic, so the service layer can't assume the payload shape is perfectly consistent. We use two coordinated mitigations on every AI-produced field:

1. **Tighten the prompt** to require the field. This reduces the rate of omission but doesn't eliminate it — the model will occasionally still ignore prompt rules.
2. **Make the service tolerant** of the omission where it can be. For example, the accomplishment confirmer falls back to global project lookup when `organization_name` is missing.

The combination is more robust than either alone. Prompts catch the common case; tolerant services catch the residual cases AND any legacy drafts already in the database from before the prompt was tightened.

This pattern repeats across the codebase wherever AI output feeds into structured data. Apply it whenever extending the extraction pipeline.

## Artisan commands

### `extraction:backfill-review-records`

Derives top-level tag/person/link review records for source documents — useful for backfilling documents extracted before the review-record derivation landed, and for refreshing review records when catalog state has changed (e.g., user added a tag alias and wants the matches to repopulate).

Usage:
- `php artisan extraction:backfill-review-records` — walks every source document, relies on `ReviewRecordExtractor`'s idempotency to skip docs that already have review records.
- `php artisan extraction:backfill-review-records --document=N` — targets one document.
- `php artisan extraction:backfill-review-records --force` — deletes pending tag/person/link review records first, then re-derives. Useful when catalog tags or aliases have been added since the original derivation and the user wants `match_record_id` to repopulate. Prompts for confirmation; bypass with `--no-interaction`. Never touches confirmed/rejected/merged records (user decisions are durable) or entity drafts.