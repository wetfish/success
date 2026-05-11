# Frontend

Server-rendered Blade with Tailwind 4 and Vite. Minimal inline JavaScript for the few interactive bits.

## Build

During development, run the Vite dev server in a separate terminal:

```bash
cd laravel && npm run dev
```

For production builds:

```bash
cd laravel && npm run build
```

If neither has been run, the page renders unstyled. The `@vite()` directive needs either a running dev server or a built `public/build/manifest.json`.

## Design system

All colors and shared values live as CSS custom properties in `resources/css/app.css` under `@theme`. To re-skin the app, edit those values; templates don't need to change.

The body background is two layers: a tiled SVG of small dots (the "sparkles") on top of a radial gradient. Both are fixed during scroll. The SVG is embedded as a data URL in `app.css`.

System font stack only — no web fonts, no CDN.

## Component classes

Reusable patterns live in `app.css` under `@layer components`. Templates apply them as utility-style classes. The current set:

| Class | Used for |
|---|---|
| `.btn-primary` / `.btn-secondary` / `.btn-destructive` | Buttons |
| `.input` (with `.has-error` modifier) | Text inputs, textareas, selects, date inputs, number inputs |
| `.link-subtle` / `.link-emphasis` | Two link styles |
| `.field-label` / `.field-label-hint` / `.field-help` / `.field-error` | Form field labels and helpers |
| `.section-heading` | Small uppercase form section headers |
| `.metadata-label` | `<dt>` elements in show-page metadata grids and in-form labels |
| `.status-banner` | Flash message banner |
| `.list-row` | Clickable rows in entity lists |
| `.modal-overlay` / `.modal-backdrop` / `.modal-panel` / `.modal-title` / `.modal-message` / `.modal-actions` | Confirmation dialogs |
| `.status-badge` with `.status-badge-rejected` / `.status-badge-confirmed` / `.status-badge-merged` | Small pill labels for review statuses |

Extract a new component class when the same pattern appears at least twice with no significant variation. Until then, inline styling is fine.

Interactive component classes (buttons, links, inputs) include `:focus-visible` rules with a visible outline for keyboard accessibility. New interactive classes should follow the same pattern.

### Modals

The modal classes work together: `.modal-overlay` is the fixed-position container, `.modal-backdrop` is the blurred backdrop click target, and `.modal-panel` is the visible card. Toggle visibility via `.is-open` on the overlay element.

The modal must include the `inert` attribute when closed and remove it when opened so focus and keyboard navigation don't reach the hidden content. Controller JS lives inline in the view as an IIFE (see Inline JavaScript below). The pattern was established for the cascade rejection dialog and works for any confirm-this-action interaction.

### Status badges

Pending drafts use no badge (it's the default state). Rejected/confirmed/merged each get a colored pill via `.status-badge` plus the status-specific modifier. The colors are deliberate: muted grey for rejected (deemphasis), green for confirmed (success), blue for merged (action taken).

### Progress bar

The draft review queue uses a 2px-tall progress bar with a fading-accent gradient. Lower-opacity accent on the left, full accent on the right, giving the bar visual direction. The treatment is inline styling in the view rather than a reusable class — it's only used in one place. If a second use case emerges, extract it.

## Blade structure

Each entity's views live under `resources/views/{entity}/`:

- `index.blade.php` — list view (when applicable)
- `create.blade.php` and `edit.blade.php` — both wrap `_form.blade.php`
- `show.blade.php` — single entity view
- `_form.blade.php` — shared form partial

The `_form` partial reads from `$entity`, which is either an unsaved instance (create) or the existing record (edit). One template handles both flows.

All views extend `layouts/app.blade.php` and override `@section('title')` and `@section('content')`.

## Inline JavaScript

A few forms have small interactive behaviors (toggling visibility of conditional fields, swapping date input groups based on precision). These are plain `<script>` blocks at the bottom of the form partial, wrapped in IIFEs. No framework, no jQuery.

The cascade rejection modal uses the same pattern at the page level — IIFE controller, plain DOM API, `data-` attributes for the elements it manages. Cancel button, backdrop click, and `Escape` key all dismiss; `body { overflow: hidden }` is set on open to prevent background scrolling.

Extract to a separate file under `resources/js/` if the same JS appears across multiple forms. None has reached that threshold yet.

## Editable draft card pattern

The draft review page uses a hybrid pattern that the rest of the app may eventually want to adopt: the same card renders either a form (when the data is editable) or a read-only definition list (when it's not). Both branches walk the same field schema, so labels and field ordering stay consistent across modes.

For the review queue specifically:
- Pending drafts render the form. Submit is bound to a Confirm button placed outside the form via `<button type="submit" form="confirm-form">` (HTML5 cross-reference). This keeps the action bar visually separate from the card while still acting on the form.
- Rejected/confirmed/merged drafts render the read-only definition list with the same field labels.

The schema lives in `App\Services\Drafts\DraftFieldSchema` (see [services doc](02-services-and-commands.md#appservicesdraftsdraftfieldschema)). Field types map to standard HTML inputs: `text` → text input, `textarea` → textarea, `select` → select with options, `date` → date input, `number` → number input.

Required fields show a pink asterisk. Optional fields render only if the payload has a value (so the form doesn't overwhelm the user with every possible optional field). Optional help text appears below the input where the schema provides it.