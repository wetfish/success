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

## Polymorphic section partials

When a child entity attaches to multiple parent types (e.g., links, which attach to organizations, projects, positions, and accomplishments — and eventually people), its "render this entity's collection on a parent page" markup lives in a single shared partial at `resources/views/{child}/_section.blade.php`. Each parent's show page pulls it in with `@include('child._section', ['linkable' => $parent])`.

The partial is self-contained: it resolves the "Add" route from the parent's class via a `match` expression, so callers don't need to pass it in. Adding a new parent type that accepts the child entity becomes a one-line change in the partial (one new `match` arm) plus the `@include` on the parent's show template.

This pattern lives today on links. Tags and people will use the same shape when those slices land.

## Editable draft card pattern

The draft review page uses a hybrid pattern that the rest of the app may eventually want to adopt: the same card renders either a form (when the data is editable) or a read-only definition list (when it's not). Both branches walk the same field schema, so labels and field ordering stay consistent across modes.

For the review queue specifically:
- Pending drafts render the form. Submit is bound to a Confirm button placed outside the form via `<button type="submit" form="confirm-form">` (HTML5 cross-reference). This keeps the action bar visually separate from the card while still acting on the form.
- Rejected/confirmed/merged drafts render the read-only definition list with the same field labels.

The schema lives in `App\Services\Drafts\DraftFieldSchema` (see [services doc](02-services-and-commands.md#appservicesdraftsdraftfieldschema)). Field types map to standard HTML inputs: `text` → text input, `textarea` → textarea, `select` → select with options, `date` → date input, `number` → number input.

Nested array fields (`tag_list`, `collaborator_list`, `link_list`) render read-only on the entity-draft review page — they're surfaced for visibility but not editable in that surface. Edits happen via the wizard's earlier steps (tag review for tags, future person review for people) or via the catalog edit pages.

Required fields show a pink asterisk. Optional fields render only if the payload has a value (so the form doesn't overwhelm the user with every possible optional field). Optional help text appears below the input where the schema provides it.

## Tag review page

The wizard's step 1: dedicated page at `/source-documents/{doc}/review/tags` for accepting, rejecting, or aliasing extracted tag names. Lives at `resources/views/tag-reviews/show.blade.php` plus `_record.blade.php` for the per-record partial.

**Layout.** Header reuses the entity-draft page's progress bar pattern (gradient pink bar + "X of Y reviewed" counter). Records group by AI-emitted category (with an `Uncategorized` bucket for off-enum categories), one card per record. Three action buttons per card (Accept / Alias to… / Reject) stay visible at all times so users can re-decide. Card border tints by status: pink for approved (confirmed or merged), muted grey for rejected, default for pending. A Next button at the bottom is disabled until pending count hits zero.

**Mentions context.** Each card shows up to three entity drafts that reference the tag, with "+N more" for additional mentions. The controller builds a `$mentions` map by walking pending entity drafts' nested `tags` arrays case-insensitively. Helps the user judge what's worth keeping.

**JavaScript split into two files.**

- `resources/js/tag-review.js` — auto-mounts on `[data-tag-review]`, intercepts action button clicks, POSTs JSON to the controller endpoints, updates the page's DOM state on response. Tracks pending count and updates the progress bar fill in place. Manages a single shared alias picker that gets repositioned per record on Alias clicks.
- `resources/js/alias-picker.js` — library module exposing `createAliasPicker({container, searchUrl, onSelect, onCancel})`. Reuses the existing `tags.search` endpoint plus `tag-picker-*` CSS classes. Single-select, callback-based, no chips or hidden form inputs.

**Data attribute contract.** The Blade emits a stable set of `data-*` attributes the JS reads:

| Element | Attribute | Purpose |
|---|---|---|
| `[data-tag-review]` (root) | `data-search-url` | URL for the alias picker's catalog search |
| | contains `[data-tag-review-next]` | Next button — JS toggles `is-disabled` class |
| | contains `[data-tag-review-reviewed-count]` | Text node updated on each decision |
| | contains `[data-tag-review-progressbar]` / `[data-tag-review-progressbar-fill]` | Width and aria-valuenow updated on each decision |
| `[data-tag-review-record]` (per card) | `data-accept-url` / `data-reject-url` / `data-alias-url` | Endpoint URLs |
| | `data-status` | Initial status; JS updates on transition |
| | contains `[data-action="accept|alias|reject"]` buttons | Inside `[data-tag-review-actions]` |
| | contains three `[data-tag-review-status-badge][data-status-badge="..."]` pills | All rendered upfront; JS toggles `hidden` |
| | contains `[data-tag-review-accept-target]` / `[data-tag-review-merge-target]` | Inside the matching pills; JS populates with catalog tag name |
| | contains `[data-tag-review-alias-slot]` | Empty container the alias picker mounts into |
| | contains `[data-tag-review-error hidden]` | Slot for inline error messages |

**JSON-only action endpoints.** Accept / Reject / Alias all return `{ok: true}` on success (Accept also includes `catalog_tag_name`) or `{error: '...'}` with a 4xx/5xx status on failure. The JS client checks `response.ok && parsed.ok` and surfaces the `error` text inline near the record on failure. No partial HTML, no 204s — uniform contract.

**Status-pill styling.** `.status-badge--review-approved` (pink border + text, transparent fill) for confirmed and merged; existing `.status-badge-rejected` (muted slate) for rejected. The two approved states share a color but render different text: "Accepted as X" vs. "Merged into Y." Card borders use `.tag-review-card`, `.tag-review-card--approved`, and `.tag-review-card--rejected` classes — reused across all three review pages despite the `tag-` prefix.

## Person review page

Wizard step 2: dedicated page at `/source-documents/{doc}/review/people` for accepting or rejecting extracted person names. Same structure as the tag review page but simpler — no alias action, no category grouping. Lives at `resources/views/people-reviews/show.blade.php` plus `_record.blade.php`.

**Mentions include role context.** Each person card shows which entity drafts reference that person and in what role (e.g., "Mentioned on: Senior Engineer as Manager (position)"). The role comes from the entity draft's nested `collaborators` array.

- `resources/js/people-review.js` — auto-mounts on `[data-people-review]`, same pattern as tag-review.js. Two actions (accept/reject) instead of three. Progress bar, counter, and next button management identical to tag review. Progress bar elements are searched via `document.querySelector` (not `root.querySelector`) because they sit in the page header outside the mount root.

**Data-attribute contract:**

| Scope | Attribute | Purpose |
|---|---|---|
| `[data-people-review]` (root) | — | JS mount point |
| | contains `[data-people-review-next]` | Next button — JS toggles `is-disabled` class |
| `[data-people-review-record]` (per card) | `data-accept-url` / `data-reject-url` | Endpoint URLs |
| | `data-status` | Current decision state |
| | contains `[data-action="accept|reject"]` buttons | |
| | contains `[data-people-review-status-badge][data-status-badge="..."]` pills | JS toggles `hidden` |
| | contains `[data-people-review-error hidden]` | Inline error messages |

Progress bar elements (`data-people-review-reviewed-count`, `data-people-review-progressbar`, `data-people-review-progressbar-fill`) live outside the root in the page header.

## Link review page

Wizard step 3: dedicated page at `/source-documents/{doc}/review/links` for accepting, rejecting, and editing extracted links. Lives at `resources/views/link-reviews/show.blade.php` plus `_record.blade.php`.

Unlike tag and person review, link cards include **editable fields** (URL, title, type dropdown, description textarea, is_personal_appearance checkbox). Fields save on blur/change via the update endpoint — no explicit save button. Edits are orthogonal to accept/reject.

- `resources/js/link-review.js` — auto-mounts on `[data-link-review]`. Combines the accept/reject/counter pattern from tag-review.js with field-editing behavior. Field changes POST to the update endpoint; the response echoes back the updated payload. URL changes also update the clickable display link.

**Data-attribute contract:**

| Scope | Attribute | Purpose |
|---|---|---|
| `[data-link-review]` (root) | — | JS mount point |
| | contains `[data-link-review-next]` | Next button — JS toggles `is-disabled` class |
| `[data-link-review-record]` (per card) | `data-accept-url` / `data-reject-url` / `data-update-url` | Endpoint URLs |
| | `data-status` | Current decision state |
| | contains `[data-field="url|title|type|description|is_personal_appearance"]` | Editable fields — JS reads value on blur/change and POSTs to update URL |
| | contains `[data-link-review-url-display]` | Clickable `<a>` tag updated when URL field changes |
| | contains `[data-action="accept|reject"]` buttons | |
| | contains `[data-link-review-status-badge][data-status-badge="..."]` pills | JS toggles `hidden` |
| | contains `[data-link-review-error hidden]` | Inline error messages |

Progress bar elements (`data-link-review-reviewed-count`, `data-link-review-progressbar`, `data-link-review-progressbar-fill`) live outside the root in the page header.

## Merge editor pattern

The merge UI presents the existing record's value and the draft's value side-by-side, with a third synthesized option for textarea fields. The user picks one of the available values per field via a "Use this" button placed under each value, so what they're choosing is unambiguous at a glance.

**Layout.** On desktop, each field row renders as a three-column grid: existing | draft | synthesized. Each column shows the value with its "Use this" button directly underneath. On mobile (`< sm`), the columns stack so the buttons stay attached to their values rather than wrapping into a confusing row. Plain Tailwind responsive utilities (`grid-cols-1 sm:grid-cols-3`) handle the breakpoint — no JS resize logic.

**Two-column fields.** Non-textarea fields (single-line text, dates, selects, numbers) render with only two columns — existing and draft. Synthesis is reserved for prose where combining two versions produces something meaningful; combining two dates or two select values doesn't.

**Synthesis is fetched on click.** The synthesized column initially shows a "Synthesize" button. Clicking it posts the field name plus both source values to the synthesize endpoint, swaps in the returned text and a "Use this" button. Errors surface inline with a retry. We don't pre-synthesize all fields on page load because the user often picks "use existing" or "use draft" for most fields — pre-fetching would waste tokens.

**Selection state.** Each field has a hidden input (`name="fields[{key}]"`) that holds the currently-selected value. Clicking a "Use this" button updates the hidden input and visually highlights the chosen cell. The form submit then has the resolved final values for every field; the controller doesn't need to know which side won, only the resolved value.

**Candidate picker.** When duplicate detection returns more than one match, the merge route first renders a small picker — a list of existing records each with a "Merge into this one" link. The link sets `?candidate_id=` in the URL and re-enters the same view, which then renders the editor. Single-candidate merges skip the picker entirely.

The pattern uses inline-IIFE script blocks consistent with the rest of the app (see Inline JavaScript above). No new framework, no separate JS file yet — extract if a second editor of this shape lands.