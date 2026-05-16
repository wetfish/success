/**
 * Alias picker.
 *
 * Single-select tag picker for choosing an alias target during tag
 * review. Reuses the tag-picker visual styles (dropdown, result rows,
 * highlight) and the tags.search endpoint for results, but is its
 * own component since the use case is different: no chips, no hidden
 * form inputs, no multi-select — just "pick one tag and fire a
 * callback."
 *
 * Mounts imperatively: code creating the picker calls
 *   const picker = createAliasPicker({ container, searchUrl, onSelect, onCancel });
 *   picker.open();
 *
 * The picker is responsible for its own DOM (input + dropdown) inside
 * the given container. Tag review uses one shared picker that gets
 * repositioned next to whichever record is being aliased — rather
 * than instantiating a separate picker per record. See tag-review.js
 * for the host wiring.
 *
 * Keyboard:
 *   ArrowDown / ArrowUp — navigate result rows
 *   Enter               — select highlighted row, fire onSelect
 *   Escape              — close picker, fire onCancel
 *
 * The picker doesn't manage its own visibility past the container
 * being attached/detached. Callers handle the host-level show/hide.
 */

const DEBOUNCE_MS = 150;
const MIN_QUERY_LENGTH = 1;

export function createAliasPicker({ container, searchUrl, onSelect, onCancel }) {
    if (!container || !searchUrl || typeof onSelect !== 'function') {
        console.warn('[alias-picker] Missing required configuration.');
        return null;
    }

    // Build the inner DOM. Reuses tag-picker classes for visual
    // consistency — same dropdown, same result row styling.
    container.innerHTML = `
        <div class="tag-picker-input-wrap">
            <input
                type="text"
                class="tag-picker-input input"
                placeholder="Search tags..."
                autocomplete="off"
                data-alias-picker-input
            />
        </div>
        <ul class="tag-picker-dropdown" role="listbox" data-alias-picker-dropdown hidden></ul>
    `;

    const input = container.querySelector('[data-alias-picker-input]');
    const dropdown = container.querySelector('[data-alias-picker-dropdown]');

    let debounceTimer = null;
    let currentResults = [];
    let highlightedIndex = -1;
    let activeRequestToken = 0;

    input.addEventListener('input', () => {
        const query = input.value.trim();
        if (debounceTimer) clearTimeout(debounceTimer);
        if (query.length < MIN_QUERY_LENGTH) {
            closeDropdown();
            return;
        }
        debounceTimer = setTimeout(() => fetchAndRender(query), DEBOUNCE_MS);
    });

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
            if (highlightedIndex >= 0 && currentResults.length > 0) {
                e.preventDefault();
                selectResult(currentResults[highlightedIndex]);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            if (typeof onCancel === 'function') onCancel();
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
                throw new Error(`alias picker search failed: ${response.status}`);
            }

            const results = await response.json();
            if (requestToken !== activeRequestToken) return;

            currentResults = Array.isArray(results) ? results : [];
            renderResults();
        } catch (err) {
            console.warn('[alias-picker] Search request failed:', err);
            if (requestToken !== activeRequestToken) return;
            currentResults = [];
            renderResults();
        }
    }

    function renderResults() {
        dropdown.innerHTML = '';

        if (currentResults.length === 0) {
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
            const li = document.createElement('li');
            li.className = 'tag-picker-result';
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.dataset.index = String(idx);

            const categoryEl = document.createElement('div');
            categoryEl.className = 'tag-picker-result-category';
            categoryEl.textContent = row.category_label || 'Uncategorized';
            li.appendChild(categoryEl);

            const nameRow = document.createElement('div');
            nameRow.className = 'tag-picker-result-name-row';
            const nameEl = document.createElement('span');
            nameEl.className = 'tag-picker-result-name';
            nameEl.textContent = row.name;
            nameRow.appendChild(nameEl);

            if (row.matched_alias) {
                const aliasNote = document.createElement('span');
                aliasNote.className = 'tag-picker-result-alias-note';
                aliasNote.textContent = `(matched: ${row.matched_alias})`;
                nameRow.appendChild(aliasNote);
            }
            li.appendChild(nameRow);

            li.addEventListener('click', () => selectResult(row));
            li.addEventListener('mouseenter', () => setHighlight(idx));

            dropdown.appendChild(li);
        });

        highlightedIndex = 0;
        applyHighlight();
        openDropdown();
    }

    function moveHighlight(delta) {
        if (currentResults.length === 0) return;
        const next = (highlightedIndex + delta + currentResults.length) % currentResults.length;
        setHighlight(next);
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

    function selectResult(row) {
        // Pass the full row to the consumer — id, name, category, etc.
        // The consumer can choose what to display in the UI after
        // selection without needing another round-trip.
        onSelect(row);
    }

    function openDropdown() { dropdown.hidden = false; }
    function closeDropdown() { dropdown.hidden = true; currentResults = []; highlightedIndex = -1; }

    return {
        focus: () => input.focus(),
        clear: () => { input.value = ''; closeDropdown(); },
    };
}