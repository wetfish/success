/**
 * Tag picker.
 *
 * Auto-mounts on every `[data-tag-picker]` element in the DOM on
 * load. Each picker provides multi-select tag input with async
 * autocomplete against the `tags.search` endpoint.
 *
 * Server-rendered chips (with their hidden inputs already populated)
 * represent the initial selection. The picker reads the chips on
 * mount to determine which tag IDs are already attached, and filters
 * them out of new autocomplete suggestions (shows them as already-
 * selected with a checkmark, not selectable).
 *
 * Keyboard:
 *   ArrowDown / ArrowUp — navigate result rows
 *   Enter               — select highlighted row
 *   Escape              — close dropdown
 *
 * Required DOM structure inside `[data-tag-picker]`:
 *   - `[data-tag-picker-chips]` — container for the selected chips
 *   - `[data-tag-picker-input]` — the search text input
 *   - `[data-tag-picker-dropdown]` — the <ul> for result rows
 *   - per chip: `[data-tag-id="N"]` with a `[data-tag-picker-remove]`
 *     button inside
 *
 * Required data attributes on `[data-tag-picker]`:
 *   - data-input-name — the form field name for the hidden inputs
 *   - data-search-url — the search endpoint URL
 *   - data-manage-url — link to the tag management page (used in the
 *                       "no matches" empty state)
 */

const DEBOUNCE_MS = 150;
const MIN_QUERY_LENGTH = 1;

/**
 * Initialize a single tag picker. Wires up event listeners and
 * sets up internal state. Returns nothing — state lives on the
 * DOM and in closure scope.
 */
function initTagPicker(root) {
    const chipsEl = root.querySelector('[data-tag-picker-chips]');
    const input = root.querySelector('[data-tag-picker-input]');
    const dropdown = root.querySelector('[data-tag-picker-dropdown]');
    const inputName = root.dataset.inputName || 'tag_ids';
    const searchUrl = root.dataset.searchUrl;
    const manageUrl = root.dataset.manageUrl;

    if (!chipsEl || !input || !dropdown || !searchUrl) {
        // Picker is malformed — log and bail rather than crash.
        console.warn('[tag-picker] Missing required elements or data attributes; skipping init.');
        return;
    }

    /** Track which tag IDs are currently selected. Reads existing chips on init. */
    const selectedIds = new Set(
        Array.from(chipsEl.querySelectorAll('[data-tag-id]'))
            .map((el) => Number(el.dataset.tagId))
            .filter((n) => Number.isFinite(n))
    );

    let debounceTimer = null;
    let currentResults = []; // last fetched result rows
    let highlightedIndex = -1; // -1 = nothing highlighted
    let activeRequestToken = 0; // discards out-of-order responses

    // Wire chip remove buttons that were server-rendered.
    bindChipRemovers(chipsEl, selectedIds);

    // Search-as-you-type with debounce.
    input.addEventListener('input', () => {
        const query = input.value.trim();
        if (debounceTimer) clearTimeout(debounceTimer);
        if (query.length < MIN_QUERY_LENGTH) {
            closeDropdown();
            return;
        }
        debounceTimer = setTimeout(() => fetchAndRender(query), DEBOUNCE_MS);
    });

    // Keyboard navigation. Arrow keys move highlight; Enter selects;
    // Escape closes. Tab is allowed to behave normally (lets the user
    // move past the picker without picking a tag).
    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (currentResults.length === 0) return;
            moveHighlight(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (currentResults.length === 0) return;
            moveHighlight(-1);
        } else if (e.key === 'Enter') {
            // Only intercept Enter if the dropdown is open, a row is
            // highlighted, and the highlighted row isn't already
            // selected. Otherwise let the form submit naturally (or
            // do nothing if there's no form action on Enter).
            if (
                !dropdown.hidden
                && highlightedIndex >= 0
                && currentResults.length > 0
                && !selectedIds.has(currentResults[highlightedIndex].id)
            ) {
                e.preventDefault();
                selectResult(currentResults[highlightedIndex]);
            } else if (!dropdown.hidden) {
                // Dropdown is open but selection would no-op. Suppress
                // Enter so the form doesn't submit unexpectedly while
                // the user is in autocomplete mode.
                e.preventDefault();
            }
        } else if (e.key === 'Escape') {
            if (!dropdown.hidden) {
                e.preventDefault();
                closeDropdown();
            }
        }
    });

    // Click outside the picker closes the dropdown.
    document.addEventListener('click', (e) => {
        if (!root.contains(e.target)) {
            closeDropdown();
        }
    });

    /**
     * Fetch results for the given query and render them. Uses a
     * request token so a slow response from an earlier query can't
     * overwrite a fresh response from a later one.
     */
    async function fetchAndRender(query) {
        const requestToken = ++activeRequestToken;

        try {
            const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`tag search failed: ${response.status}`);
            }

            const results = await response.json();

            // Discard if a newer request superseded this one.
            if (requestToken !== activeRequestToken) return;

            currentResults = Array.isArray(results) ? results : [];
            renderResults();
        } catch (err) {
            console.warn('[tag-picker] Search request failed:', err);
            if (requestToken !== activeRequestToken) return;
            currentResults = [];
            renderResults();
        }
    }

    /**
     * Render the result rows into the dropdown. Two-line rows: muted
     * category label on top, tag name below. Alias matches add a
     * third line. Already-selected tags get a pink checkmark instead
     * of being hidden — gives the user a "you already have this"
     * affordance.
     */
    function renderResults() {
        dropdown.innerHTML = '';

        if (currentResults.length === 0) {
            // Greyed-out "no matches" row, non-interactive.
            const empty = document.createElement('li');
            empty.className = 'tag-picker-result tag-picker-result--empty';
            empty.setAttribute('role', 'option');
            empty.setAttribute('aria-disabled', 'true');
            empty.textContent = 'No matches.';
            dropdown.appendChild(empty);
            highlightedIndex = -1;
            openDropdown();
            return;
        }

        currentResults.forEach((row, idx) => {
            const isSelected = selectedIds.has(row.id);
            const li = document.createElement('li');
            li.className = 'tag-picker-result';
            if (isSelected) li.classList.add('is-selected');
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            if (isSelected) li.setAttribute('aria-disabled', 'true');
            li.dataset.index = String(idx);

            // Category line (muted, top). If category is null, render
            // an em-dash so the row layout stays consistent — keeps
            // the two-line height stable across rows.
            const categoryEl = document.createElement('div');
            categoryEl.className = 'tag-picker-result-category';
            categoryEl.textContent = row.category_label || 'Uncategorized';
            li.appendChild(categoryEl);

            // Name line (primary). For alias matches, append the
            // matched-alias hint inline.
            const nameRow = document.createElement('div');
            nameRow.className = 'tag-picker-result-name-row';

            const nameEl = document.createElement('span');
            nameEl.className = 'tag-picker-result-name';
            nameEl.textContent = row.name;
            nameRow.appendChild(nameEl);

            if (row.matched_alias) {
                const aliasHint = document.createElement('span');
                aliasHint.className = 'tag-picker-result-alias-hint';
                aliasHint.textContent = `matched: ${row.matched_alias}`;
                nameRow.appendChild(aliasHint);
            }

            if (isSelected) {
                // Pink checkmark for already-selected. Rendered as
                // SVG so stroke follows currentColor.
                const check = document.createElement('span');
                check.className = 'tag-picker-result-check';
                check.setAttribute('aria-label', 'Already selected');
                check.innerHTML = `
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="2,7 6,11 12,3"/>
                    </svg>
                `;
                nameRow.appendChild(check);
            }

            li.appendChild(nameRow);

            // Click handler — selectable only if not already selected.
            li.addEventListener('click', () => {
                if (isSelected) return;
                selectResult(row);
            });

            // Hover updates the highlight (mouse and keyboard nav
            // stay in sync). Already-selected rows don't highlight
            // since they can't be selected.
            li.addEventListener('mouseenter', () => {
                if (isSelected) return;
                setHighlight(idx);
            });

            dropdown.appendChild(li);
        });

        // Highlight the first selectable result by default.
        const firstSelectable = currentResults.findIndex((row) => !selectedIds.has(row.id));
        highlightedIndex = firstSelectable >= 0 ? firstSelectable : -1;
        applyHighlight();

        openDropdown();
    }

    /**
     * Move the keyboard highlight by `delta` (1 = down, -1 = up).
     * Skips already-selected rows so the user can't try to select
     * a row that wouldn't do anything. Wraps around at the edges.
     */
    function moveHighlight(delta) {
        if (currentResults.length === 0) return;

        // Build list of selectable indices.
        const selectable = currentResults
            .map((row, idx) => (selectedIds.has(row.id) ? null : idx))
            .filter((idx) => idx !== null);

        if (selectable.length === 0) return;

        const currentPos = selectable.indexOf(highlightedIndex);
        let nextPos;
        if (currentPos === -1) {
            nextPos = delta > 0 ? 0 : selectable.length - 1;
        } else {
            nextPos = (currentPos + delta + selectable.length) % selectable.length;
        }
        setHighlight(selectable[nextPos]);
    }

    function setHighlight(idx) {
        highlightedIndex = idx;
        applyHighlight();
    }

    function applyHighlight() {
        Array.from(dropdown.children).forEach((li, idx) => {
            if (idx === highlightedIndex) {
                li.classList.add('is-highlighted');
                li.setAttribute('aria-selected', 'true');
            } else {
                li.classList.remove('is-highlighted');
                li.setAttribute('aria-selected', 'false');
            }
        });
    }

    /**
     * Select a tag — create a chip with its hidden input, register
     * the ID in the selected set, clear the input, and re-fire the
     * search with the (now-empty) input so the dropdown closes.
     */
    function selectResult(row) {
        selectedIds.add(row.id);

        const chip = document.createElement('span');
        chip.className = 'tag-picker-chip';
        chip.dataset.tagId = String(row.id);
        chip.innerHTML = `
            <span class="tag-picker-chip-label"></span>
            <button
                type="button"
                class="tag-picker-chip-remove"
                aria-label="Remove ${escapeAttr(row.name)}"
                data-tag-picker-remove
            >
                <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <line x1="2" y1="2" x2="8" y2="8"/>
                    <line x1="8" y1="2" x2="2" y2="8"/>
                </svg>
            </button>
            <input type="hidden" name="${escapeAttr(inputName)}[]" value="${row.id}">
        `;
        // Set the label as textContent (not innerHTML) so tag names
        // with HTML special chars render correctly.
        chip.querySelector('.tag-picker-chip-label').textContent = row.name;

        bindChipRemoveButton(chip.querySelector('[data-tag-picker-remove]'), chip);

        chipsEl.appendChild(chip);

        // Clear input and close dropdown. The user can keep typing
        // to add more tags rapidly — common workflow when tagging
        // an accomplishment with multiple technologies.
        input.value = '';
        closeDropdown();
        input.focus();
    }

    function bindChipRemovers(container, selectedIdSet) {
        container.querySelectorAll('[data-tag-picker-remove]').forEach((btn) => {
            const chip = btn.closest('[data-tag-id]');
            if (chip) bindChipRemoveButton(btn, chip);
        });
    }

    function bindChipRemoveButton(btn, chip) {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const id = Number(chip.dataset.tagId);
            if (Number.isFinite(id)) selectedIds.delete(id);
            chip.remove();
            input.focus();
        });
    }

    function openDropdown() {
        dropdown.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function closeDropdown() {
        dropdown.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        highlightedIndex = -1;
    }
}

/**
 * HTML attribute-safe escape. Used when interpolating user-supplied
 * strings into innerHTML (the alternative would be building each
 * element with createElement, which is more verbose for the chip
 * structure).
 */
function escapeAttr(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

// Auto-mount on every picker in the DOM. The auto-mount is the
// only public surface — there's no exported initTagPicker because
// callers shouldn't need to manually wire up pickers.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-tag-picker]').forEach(initTagPicker);
});