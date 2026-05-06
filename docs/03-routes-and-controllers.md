# Routes and Controllers

All routes live in `routes/web.php`. No API routes yet — the app is server-rendered Blade.

## Current entities

| Entity | Top-level routes | Create-in-context routes |
|---|---|---|
| Organization | full resource | — |
| Position | resource minus `index` and `create` | `organizations/{org}/positions/create` |
| Project | resource minus `index` and `create` | three: under organization, under position, under parent project |

Each entity has its own controller in `app/Http/Controllers/` and a corresponding `{Entity}CrudTest` in `tests/Feature/`.

## Form requests

Each entity that accepts writes has three classes in `app/Http/Requests/`:

- `{Entity}Rules` — shared validation rules and input normalization. Holds accepted-value constants used by both controllers and views.
- `Store{Entity}Request` — delegates to `{Entity}Rules::rules()` and `::normalize()`
- `Update{Entity}Request` — same

`prepareForValidation()` runs `Rules::normalize()` to trim strings, convert empty strings to null, strip thousands separators from numeric inputs, and any per-entity cleanup (e.g., clearing `reason_for_leaving_notes` when the reason is empty).

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