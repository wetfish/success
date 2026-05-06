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
| `.input` (with `.has-error` modifier) | Text inputs, textareas, selects |
| `.link-subtle` / `.link-emphasis` | Two link styles |
| `.field-label` / `.field-label-hint` / `.field-help` / `.field-error` | Form field labels and helpers |
| `.section-heading` | Small uppercase form section headers |
| `.metadata-label` | `<dt>` elements in show-page metadata grids |
| `.status-banner` | Flash message banner |
| `.list-row` | Clickable rows in entity lists |

Extract a new component class when the same pattern appears at least twice with no significant variation. Until then, inline styling is fine.

Interactive component classes (buttons, links, inputs) include `:focus-visible` rules with a visible outline for keyboard accessibility. New interactive classes should follow the same pattern.

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

Extract to a separate file under `resources/js/` if the same JS appears across multiple forms. None has reached that threshold yet.