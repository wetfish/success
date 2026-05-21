/**
 * Catalog search picker for Screen 2 of the resume wizard.
 *
 * Single-action autocomplete: search across organizations, positions,
 * projects, and accomplishments. Selecting a result submits a hidden
 * form to add the entry as a selection on the current requirement.
 *
 * Simpler than the tag/person/org pickers — no chips, no persistent
 * selection, no multi-select. Each pick fires a form submission that
 * reloads the page with the new selection card visible.
 *
 * Auto-mounts on every `[data-catalog-picker]` element on load.
 *
 * Required DOM structure inside `[data-catalog-picker]`:
 *   - `[data-catalog-picker-input]` — the search text input
 *   - `[data-catalog-picker-dropdown]` — the <ul> for result rows
 *   - a <form> with hidden inputs for selectable_type and selectable_id
 *
 * Required data attributes on `[data-catalog-picker]`:
 *   - data-search-url — the catalog search endpoint URL
 */

const DEBOUNCE_MS = 150;
const MIN_QUERY_LENGTH = 2;

function initCatalogPicker(root) {
    const input = root.querySelector('[data-catalog-picker-input]');
    const dropdown = root.querySelector('[data-catalog-picker-dropdown]');
    const form = root.querySelector('[data-catalog-picker-form]');
    const typeInput = form?.querySelector('input[name="selectable_type"]');
    const idInput = form?.querySelector('input[name="selectable_id"]');
    const searchUrl = root.dataset.searchUrl;

    if (!input || !dropdown || !form || !typeInput || !idInput || !searchUrl) {
        return;
    }

    let debounceTimer = null;
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
            if (dropdown.hidden) return;
            moveHighlight(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (dropdown.hidden) return;
            moveHighlight(-1);
        } else if (e.key === 'Enter') {
            if (!dropdown.hidden && highlightedIndex >= 0) {
                e.preventDefault();
                const items = dropdown.querySelectorAll('[data-index]');
                const highlighted = items[highlightedIndex];
                if (highlighted) highlighted.click();
            } else if (!dropdown.hidden) {
                // Suppress Enter while dropdown is open to prevent form submit.
                e.preventDefault();
            }
        } else if (e.key === 'Escape') {
            if (!dropdown.hidden) {
                e.preventDefault();
                closeDropdown();
            }
        }
    });

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

            if (!response.ok) throw new Error(`search failed: ${response.status}`);

            const results = await response.json();
            if (requestToken !== activeRequestToken) return;

            renderResults(Array.isArray(results) ? results : []);
        } catch (err) {
            console.warn('[catalog-picker] Search failed:', err);
            if (requestToken !== activeRequestToken) return;
            renderResults([]);
        }
    }

    function renderResults(results) {
        dropdown.innerHTML = '';

        if (results.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'catalog-picker-result catalog-picker-result--empty';
            empty.setAttribute('role', 'option');
            empty.setAttribute('aria-disabled', 'true');
            empty.textContent = 'No matches found.';
            dropdown.appendChild(empty);
            highlightedIndex = -1;
            openDropdown();
            return;
        }

        results.forEach((row, idx) => {
            const li = document.createElement('li');
            li.className = 'catalog-picker-result';
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.dataset.index = String(idx);

            // Type badge + name on the first line.
            const nameRow = document.createElement('div');
            nameRow.className = 'catalog-picker-result-name';

            const badge = document.createElement('span');
            badge.className = 'catalog-picker-result-badge';
            badge.textContent = row.type;
            nameRow.appendChild(badge);

            const name = document.createElement('span');
            name.textContent = row.name;
            nameRow.appendChild(name);

            li.appendChild(nameRow);

            // Context line.
            if (row.context) {
                const context = document.createElement('div');
                context.className = 'catalog-picker-result-context';
                context.textContent = row.context;
                li.appendChild(context);
            }

            li.addEventListener('click', () => {
                typeInput.value = row.type;
                idInput.value = row.id;
                form.submit();
            });

            li.addEventListener('mouseenter', () => {
                highlightedIndex = idx;
                applyHighlight();
            });

            dropdown.appendChild(li);
        });

        highlightedIndex = 0;
        applyHighlight();
        openDropdown();
    }

    function moveHighlight(delta) {
        const items = dropdown.querySelectorAll('[data-index]');
        if (items.length === 0) return;
        highlightedIndex = (highlightedIndex + delta + items.length) % items.length;
        applyHighlight();
    }

    function applyHighlight() {
        const items = dropdown.querySelectorAll('[data-index]');
        items.forEach((li) => {
            const isHighlighted = Number(li.dataset.index) === highlightedIndex;
            li.classList.toggle('is-highlighted', isHighlighted);
            li.setAttribute('aria-selected', isHighlighted ? 'true' : 'false');
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

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-catalog-picker]').forEach(initCatalogPicker);
});