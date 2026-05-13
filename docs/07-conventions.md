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