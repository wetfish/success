/**
 * Person picker.
 *
 * Auto-mounts on every `[data-person-picker]` element in the DOM
 * on load. Provides multi-select people input with async
 * autocomplete against the `people.search` endpoint, plus a
 * free-text role field per selected chip.
 *
 * Server-rendered chips (with their hidden inputs already populated)
 * represent the initial selection. The picker reads the chips on
 * mount to determine which person IDs are already attached, and
 * filters them out of new autocomplete selection (shows them as
 * already-selected with a checkmark, not selectable).
 *
 * Keyboard:
 *   ArrowDown / ArrowUp — navigate result rows
 *   Enter               — select highlighted row
 *   Escape              — close dropdown
 *
 * Required DOM structure inside `[data-person-picker]`:
 *   - `[data-person-picker-chips]` — container for the selected chips
 *   - `[data-person-picker-input]` — the search text input
 *   - `[data-person-picker-dropdown]` — the <ul> for result rows
 *   - per chip: `[data-person-id="N"]` with a `[data-person-picker-remove]`
 *     button inside, a `.person-picker-chip-role` text input, and a
 *     hidden person_id input.
 *
 * Required data attributes on `[data-person-picker]`:
 *   - data-input-name — base form field name; chips contribute
 *     {name}[i][person_id] and {name}[i][role] fields
 *   - data-search-url — the search endpoint URL
 *   - data-role-datalist — the id of the <datalist> for role suggestions
 */

const DEBOUNCE_MS = 150;
const MIN_QUERY_LENGTH = 1;

function initPersonPicker(root) {
    const chipsEl = root.querySelector('[data-person-picker-chips]');
    const input = root.querySelector('[data-person-picker-input]');
    const dropdown = root.querySelector('[data-person-picker-dropdown]');
    const inputName = root.dataset.inputName || 'collaborators';
    const searchUrl = root.dataset.searchUrl;
    const roleDatalist = root.dataset.roleDatalist;

    if (!chipsEl || !input || !dropdown || !searchUrl) {
        console.warn('[person-picker] Missing required elements or data attributes; skipping init.');
        return;
    }

    /** Selected person IDs read from server-rendered chips on init. */
    const selectedIds = new Set(
        Array.from(chipsEl.querySelectorAll('[data-person-id]'))
            .map((el) => Number(el.dataset.personId))
            .filter((n) => Number.isFinite(n))
    );

    /**
     * Monotonic chip index counter. New chips get indices starting
     * after the highest server-rendered index. Indices never get
     * reused — removing a chip doesn't renumber the others. Laravel
     * collects sparse arrays correctly.
     *
     * Initialize from the count of existing chips (server-rendered
     * uses $loop->index, so they're 0..N-1). New chips start at N.
     */
    let nextChipIndex = chipsEl.querySelectorAll('[data-person-id]').length;

    let debounceTimer = null;
    let currentResults = [];
    let highlightedIndex = -1;
    let activeRequestToken = 0;

    // Wire chip remove buttons that were server-rendered.
    bindServerChipRemovers();

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

    // Keyboard navigation.
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
            if (
                !dropdown.hidden
                && highlightedIndex >= 0
                && currentResults.length > 0
                && !selectedIds.has(currentResults[highlightedIndex].id)
            ) {
                e.preventDefault();
                selectResult(currentResults[highlightedIndex]);
            } else if (!dropdown.hidden) {
                // Suppress Enter while autocomplete mode is active so
                // a stray press doesn't submit the parent form.
                e.preventDefault();
            }
        } else if (e.key === 'Escape') {
            if (!dropdown.hidden) {
                e.preventDefault();
                closeDropdown();
            }
        }
    });

    // Click outside picker closes dropdown.
    document.addEventListener('click', (e) => {
        if (!root.contains(e.target)) {
            closeDropdown();
        }
    });

    async function fetchAndRender(query) {
        const requestToken = ++activeRequestToken;

        try {
            const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`person search failed: ${response.status}`);
            }

            const results = await response.json();

            if (requestToken !== activeRequestToken) return;

            currentResults = Array.isArray(results) ? results : [];
            renderResults();
        } catch (err) {
            console.warn('[person-picker] Search request failed:', err);
            if (requestToken !== activeRequestToken) return;
            currentResults = [];
            renderResults();
        }
    }

    /**
     * Render result rows. Each row is two lines: name (primary) on
     * top, current_title and current_organization_name (muted) below
     * for disambiguation. Already-selected people get a checkmark.
     */
    function renderResults() {
        dropdown.innerHTML = '';

        if (currentResults.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'person-picker-result person-picker-result--empty';
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
            li.className = 'person-picker-result';
            if (isSelected) li.classList.add('is-selected');
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            if (isSelected) li.setAttribute('aria-disabled', 'true');
            li.dataset.index = String(idx);

            // Name on top (primary).
            const nameRow = document.createElement('div');
            nameRow.className = 'person-picker-result-name-row';

            const nameEl = document.createElement('span');
            nameEl.className = 'person-picker-result-name';
            nameEl.textContent = row.name;
            nameRow.appendChild(nameEl);

            if (isSelected) {
                const check = document.createElement('span');
                check.className = 'person-picker-result-check';
                check.setAttribute('aria-label', 'Already selected');
                check.innerHTML = `
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="2,7 6,11 12,3"/>
                    </svg>
                `;
                nameRow.appendChild(check);
            }

            li.appendChild(nameRow);

            // Context line (current_title and/or current_organization_name).
            // Renders only if at least one is present. Format:
            //   "Engineering Manager · Lightning Labs"
            //   "Engineering Manager"
            //   "Lightning Labs"
            // The dot separator only appears when both exist.
            const contextParts = [];
            if (row.current_title) contextParts.push(row.current_title);
            if (row.current_organization_name) contextParts.push(row.current_organization_name);

            if (contextParts.length > 0) {
                const contextEl = document.createElement('div');
                contextEl.className = 'person-picker-result-context';
                contextEl.textContent = contextParts.join(' · ');
                li.appendChild(contextEl);
            }

            li.addEventListener('click', () => {
                if (isSelected) return;
                selectResult(row);
            });

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

    function moveHighlight(delta) {
        if (currentResults.length === 0) return;

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
     * Select a person — create a chip with name, role input, remove
     * button, and hidden person_id input. The chip uses a fresh
     * monotonic index, separate from server-rendered indices.
     */
    function selectResult(row) {
        selectedIds.add(row.id);

        const idx = nextChipIndex++;
        const chip = document.createElement('div');
        chip.className = 'person-picker-chip';
        chip.dataset.personId = String(row.id);
        chip.innerHTML = `
            <div class="person-picker-chip-name"></div>
            <input
                type="text"
                class="person-picker-chip-role input"
                name="${escapeAttr(inputName)}[${idx}][role]"
                value=""
                list="${escapeAttr(roleDatalist || '')}"
                placeholder="Role…"
                maxlength="255"
            >
            <button
                type="button"
                class="person-picker-chip-remove"
                aria-label="Remove ${escapeAttr(row.name)}"
                data-person-picker-remove
            >
                <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <line x1="2" y1="2" x2="8" y2="8"/>
                    <line x1="8" y1="2" x2="2" y2="8"/>
                </svg>
            </button>
            <input type="hidden" name="${escapeAttr(inputName)}[${idx}][person_id]" value="${row.id}">
        `;
        // Set name as textContent (not innerHTML) so names with HTML
        // special characters render correctly.
        chip.querySelector('.person-picker-chip-name').textContent = row.name;

        bindChipRemoveButton(chip.querySelector('[data-person-picker-remove]'), chip);

        chipsEl.appendChild(chip);

        // Clear the search input and close the dropdown so the user
        // can keep adding more collaborators or move on. Focus stays
        // on the search input — common pattern for capturing several
        // collaborators in a row.
        input.value = '';
        closeDropdown();
        input.focus();
    }

    function bindServerChipRemovers() {
        chipsEl.querySelectorAll('[data-person-picker-remove]').forEach((btn) => {
            const chip = btn.closest('[data-person-id]');
            if (chip) bindChipRemoveButton(btn, chip);
        });
    }

    function bindChipRemoveButton(btn, chip) {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const id = Number(chip.dataset.personId);
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

function escapeAttr(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-person-picker]').forEach(initPersonPicker);
});