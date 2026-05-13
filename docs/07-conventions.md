# Conventions

Patterns this codebase commits to. Each section answers three questions: what the pattern is, when to use it, and why it earns its place over simpler alternatives.

If you're adding a new pattern, follow the existing section shape. If you're encountering a pattern in code and wondering "why is it like this," this is where the reasoning lives.

---

## Backed enums as the single source of truth for value lists

Any value list referenced from more than one file becomes a backed enum class under `app/Enums/`. The enum is the canonical definition; every consumer reads from it.

### Why

Pseudo-enum value lists (the canonical set of types a column accepts) tend to drift when they live as duplicated arrays. The codebase hit this concretely: `OrganizationRules::TYPES` had six values, but `DraftFieldSchema::organizationFields()` had a hardcoded list with five different values — including `school` and `community`, which weren't valid types at all. That list had been written by hand to look reasonable and had quietly diverged from the actual validation rules. The AI extraction prompt had a third copy of the list that was *also* missing a value. Three sources of truth, three different truths.

A backed enum forces every consumer to read from the same place. Adding a case propagates to validation, form rendering, draft review, and the AI prompt automatically — no one can forget to update one of three lists.

### When

Convert a value list to an enum when at least two of these are true:

- The list is referenced from more than one file
- Validation rules use it (`Rule::in([...])`)
- A view iterates it to render options
- The AI extraction prompt mentions it
- New values get added occasionally and you want a single place to add them

Stay with a `const TYPES = [...]` constant on a `Rules` class when the list is genuinely local: referenced only by that file's own validation, no view, no prompt, no other consumer. `LinkRules::TYPES` is the current example — used only by `LinkRules` itself and the link form view that the same controller passes data to. Single-file scope, single source of truth already.

### Shape

Files live at `app/Enums/{Subject}{Concept}.php`, named in PascalCase singular: `OrganizationType`, `OrganizationStatus`, not `OrganizationTypes` or `OrganizationTypeEnum`.

Every enum is a backed enum (`enum Foo: string { ... }`), values are the lowercase-snake_case slugs that end up in the database. Cases are PascalCase.

```php
namespace App\Enums;

enum OrganizationType: string
{
    case Employer = 'employer';
    case Prospect = 'prospect';
    // ...

    public function label(): string
    {
        return match ($this) {
            self::Employer => 'Employer',
            self::Prospect => 'Prospect',
            // ...
        };
    }
}
```

### Required methods

**`label(): string` — human-readable display.** Always defined, always per-case. Some labels can be auto-derived from the value (`ucfirst(str_replace('_', ' ', $value))`), but the codebase doesn't rely on that — explicit labels mean future contributors don't have to wonder whether the auto-derive handles their case correctly, and adding a new case forces a deliberate label choice.

### Optional methods

**`promptEnumString(): string` — static helper for AI prompt interpolation.** Defined when the enum is referenced in the AI extraction prompt. Returns the values as a pipe-separated quoted string ready to substitute into the prompt template: `"employer" | "client" | "prospect"`. The prompt body uses `{{placeholder}}` markers that `strtr()` swaps at runtime, so adding a new case to the enum updates the prompt automatically without anyone touching `ClaudeExtractionProvider`.

Skip this method when the enum doesn't touch the AI prompt.

### Where consumers read from

- **Validation rules** use `Rule::enum(EnumClass::class)` instead of `Rule::in([...])`. The rule resolves the enum's cases at runtime.
- **Form views** iterate `EnumClass::cases()` (passed in from the controller as a view variable). The Blade template uses `$case->value` for option values and `$case->label()` for display.
- **Draft review schema** reads option lists via `array_column(EnumClass::cases(), 'value')`.
- **AI prompt** uses `strtr()` substitution with `EnumClass::promptEnumString()` (see above).
- **Eloquent models** can cast the column to the enum via `protected function casts(): array { return ['type' => OrganizationType::class]; }`. This is opt-in — see "Deferred decision" below.

### Deferred decision: Eloquent column casts

The enum is currently the source of truth for *values* — validation, rendering, and prompt interpolation all flow through it — but the model itself doesn't cast the column to the enum type. `$organization->type` returns a raw string, not an `OrganizationType` instance.

Adding the cast is mechanically simple (one line on the model) but ripples through every test assertion of the form `$this->assertSame('employer', $organization->type)`, which would need to become `$this->assertSame(OrganizationType::Employer, $organization->type)` or `$this->assertSame('employer', $organization->type->value)`. The test churn is the actual cost.

The benefit is type-safety at the call site — code that passes `$organization->type` to a function expecting `OrganizationType` would fail at the type level rather than producing a runtime error when the wrong string flows through. PHPStan/Psalm can verify it at static-analysis time.

The decision: enums currently serve as the source-of-truth for values, validation, and UI. Casting at the model level is a separate, optional graduation step that can land per-enum when the type-safety benefit outweighs the test-churn cost. When an enum graduates, its docblock should be updated to reflect the cast (and this doc updated to point at a canonical example).

### Migration path: converting an existing const array

When converting an existing `const FOO = [...]` constant on a Rules class to an enum:

1. Create the enum class under `app/Enums/`. Define cases for every value currently in the const array. Add `label()` (and `promptEnumString()` if the AI prompt references the list).
2. In the Rules class, drop the const and update the validation rule from `Rule::in(self::FOO)` to `Rule::enum(EnumClass::class)`.
3. Find every consumer of the old constant: `grep -rn 'YourRules::FOO' app/ resources/`. Update each to read from the enum directly.
4. Update form views to iterate cases and use `->value` / `->label()`.
5. If the value list is in the AI prompt, replace the hardcoded enum with a `{{placeholder}}` and add a `strtr()` substitution.
6. If `DraftFieldSchema` has an `options` array for the field, switch it to `array_column(EnumClass::cases(), 'value')`.
7. Run the test suite. Validation tests still pass because `Rule::enum()` accepts and rejects strings the same way `Rule::in()` did.

Each step is mechanical and the changes leave the system in a working state once all consumers are updated. The point in the migration where things would break is between dropping the const and updating its consumers — keep those changes together in a single slice.

### Current enums

- `App\Enums\OrganizationType` — see `app/Enums/OrganizationType.php`. Includes `promptEnumString()` (referenced in the extraction prompt).
- `App\Enums\OrganizationStatus` — see `app/Enums/OrganizationStatus.php`. Includes `promptEnumString()`.

Other value lists across the codebase (`LinkRules::TYPES`, `TagRules::CATEGORIES`, `PersonRules::RELATIONSHIP_TYPES`, position employment types, project visibility/status/contribution levels, accomplishment dating modes) still live as const arrays on their respective Rules classes. Most are single-file consumers and stay as constants. Convert one to an enum when it picks up its second consumer or when its values start touching the AI prompt.

---

## Autocomplete picker components

Reusable form controls for selecting one or more existing records (tags, people, and future others) follow a shared structural pattern, even though each picker lives in its own file. Two such pickers exist today: `resources/js/tag-picker.js` and `resources/js/person-picker.js`. Future pickers (organization, person quick-add variant) should follow the same shape.

### Why

Three or four custom autocomplete components with different keyboard handling, different request-deduplication strategies, different chip semantics, and different ARIA roles becomes a maintenance burden quickly. By committing to a shared shape — even when the code itself isn't shared — anyone building or debugging a picker can reason about familiar machinery in unfamiliar code. The patterns transfer across pickers; the content (what rows look like, what chips hold) varies.

### When to build a new picker

When a form needs to select existing records from a potentially large set (more than ~20) where a plain `<select>` becomes unwieldy. Autocomplete plus the ability to scan-and-select keyboard-first beats scrolling through a long dropdown.

When a small fixed set of options (~5–10) is all the user picks from, stay with a native `<select>` or radio group. The picker machinery is overkill there.

### Required shape

Each picker is a single JavaScript module under `resources/js/{thing}-picker.js`, a single CSS file under `resources/css/components/{thing}-picker.css` (imported from `app.css`), and a single Blade partial under `resources/views/{things}/_picker.blade.php`. The JS module auto-mounts on `[data-{thing}-picker]` elements on `DOMContentLoaded` — no manual init per form.

The DOM structure inside `[data-{thing}-picker]`:

- `[data-{thing}-picker-chips]` — container for selected chips
- `[data-{thing}-picker-input]` — the search text input
- `[data-{thing}-picker-dropdown]` — the `<ul>` for result rows
- Per chip: `[data-{thing}-id="N"]` carrying the selected record's ID, with a `[data-{thing}-picker-remove]` button inside and hidden form inputs for submission

Required data attributes on the root: `data-input-name` (base form field name), `data-search-url` (backend endpoint).

### Required behavior

- Search-as-you-type with **150ms debounce** — short enough to feel responsive, long enough to avoid firing a request on every keystroke.
- **Async race protection** via a monotonic request token. The response handler discards results that arrived for an older request than the latest one.
- **Keyboard navigation** — ArrowDown/ArrowUp to move highlight (skipping already-selected rows), Enter to select the highlighted row, Escape to close the dropdown. Enter is suppressed entirely while the dropdown is open even when no selection happens, so a stray Enter doesn't submit the parent form mid-pick.
- **Already-selected indicator** — when a record matching the search query is already in the selection set, the result row shows a pink checkmark and is not clickable. Lets the user verify "yes, I already have this one" without flipping out of the picker.
- **Click outside closes the dropdown.**
- **Server-rendered initial chips** so the form degrades gracefully without JS — the user sees their current selection and can submit unchanged even if JS fails to load.

### Required backend support

A `GET /{things}/search?q=...` endpoint that:

- Returns an empty array for empty or whitespace-only queries (the picker JS doesn't fire requests below `MIN_QUERY_LENGTH = 1`, but the endpoint should defend itself anyway).
- Caps results at 5 (or some small constant — 5 fits comfortably in the dropdown without scrolling).
- Returns ranked results — typically tier 1 for prefix matches, tier 2 for substring matches, with alphabetical tiebreaks within each tier.
- Returns enough fields in the response payload to render the dropdown row's primary line *and* a disambiguation subline (e.g., for people: name on top, current title + organization below).
- Is registered **before** the parent resource route to avoid `Route::resource` shadowing — `/people/search` would otherwise match `/people/{person}` with "search" interpreted as a person ID.

### Current pickers

- **Tag picker** — `resources/js/tag-picker.js`, `resources/css/components/tag-picker.css`, `resources/views/tags/_picker.blade.php`. Multi-select. Backend: `TagController::search`. Four-tier ranking (canonical name prefix/substring × alias prefix/substring), surfaces `matched_alias` for alias-matched rows.
- **Person picker** — `resources/js/person-picker.js`, `resources/css/components/person-picker.css`, `resources/views/people/_picker.blade.php`. Multi-select with free-text role per chip. Backend: `PersonController::search`. Two-tier ranking (name prefix/substring).

### Why pickers don't share code today

Each picker's rendering logic is small enough (~400 lines of JS) that the shared patterns end up being the patterns themselves — the debounce constant, the request token mechanism, the keyboard handler shape. Extracting a base "AutocompletePicker" class would force decisions about what's customizable (row template? chip template? both?) before we know which axes of customization actually matter.

The right time to extract a shared base is when a third picker arrives and the per-picker variation becomes clear. Two pickers is too few to know what to abstract; three or four will reveal the natural seams.