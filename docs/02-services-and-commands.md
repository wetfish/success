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

Methods: `extract()` for the main pipeline, `generateTitle()` for naming pasted text, `synthesizeDescriptions()` for the merge UI in slice 4.5. Each returns a typed result object.

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

## Defense-in-depth on AI inputs

A pattern that emerged during milestone 4: the AI is non-deterministic, so the service layer can't assume the payload shape is perfectly consistent. We use two coordinated mitigations on every AI-produced field:

1. **Tighten the prompt** to require the field. This reduces the rate of omission but doesn't eliminate it — the model will occasionally still ignore prompt rules.
2. **Make the service tolerant** of the omission where it can be. For example, the accomplishment confirmer falls back to global project lookup when `organization_name` is missing.

The combination is more robust than either alone. Prompts catch the common case; tolerant services catch the residual cases AND any legacy drafts already in the database from before the prompt was tightened.

This pattern repeats across the codebase wherever AI output feeds into structured data. Apply it whenever extending the extraction pipeline.

## Artisan commands

None project-specific yet. Default Laravel commands (`migrate`, `tinker`, `make:*`, `test`) are the only ones available.