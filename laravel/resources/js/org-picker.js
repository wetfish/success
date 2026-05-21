/**
 * Organization picker.
 *
 * Single-select autocomplete for choosing an organization. Used on
 * the job listing form to associate a listing with an org.
 *
 * Unlike the tag and person pickers (multi-select with chips), this
 * picker resolves to a single hidden `organization_id` input. When
 * the user types a name that doesn't match any existing org, the
 * dropdown offers a "Create as prospect" option that fires an AJAX
 * POST to quick-create the org and immediately selects it.
 *
 * Auto-mounts on every `[data-org-picker]` element on load.
 *
 * Required DOM structure inside `[data-org-picker]`:
 *   - `[data-org-picker-input]` — the search text input
 *   - `[data-org-picker-dropdown]` — the <ul> for result rows
 *   - `[data-org-picker-selected]` — display element for selected org name
 *   - `[data-org-picker-clear]` — button to clear the selection
 *   - a hidden input with name="organization_id"
 *
 * Required data attributes on `[data-org-picker]`:
 *   - data-search-url — the search endpoint URL
 *   - data-quick-store-url — endpoint for creating a new prospect org
 *
 * Optional data attributes for pre-population (edit forms):
 *   - data-selected-id — existing org ID
 *   - data-selected-name — existing org name
 */

const DEBOUNCE_MS = 150;
const MIN_QUERY_LENGTH = 1;

function initOrgPicker(root) {
    const input = root.querySelector('[data-org-picker-input]');
    const dropdown = root.querySelector('[data-org-picker-dropdown]');
    const selectedDisplay = root.querySelector('[data-org-picker-selected]');
    const clearBtn = root.querySelector('[data-org-picker-clear]');
    const hiddenInput = root.querySelector('input[name="organization_id"]');
    const searchUrl = root.dataset.searchUrl;
    const quickStoreUrl = root.dataset.quickStoreUrl;

    if (!input || !dropdown || !selectedDisplay || !hiddenInput || !searchUrl) {
        console.warn('[org-picker] Missing required elements or data attributes; skipping init.');
        return;
    }

    let debounceTimer = null;
    let currentResults = [];
    let highlightedIndex = -1;
    let activeRequestToken = 0;
    let lastQuery = '';

    // Pre-populate from data attributes (edit form).
    if (root.dataset.selectedId && root.dataset.selectedName) {
        applySelection(root.dataset.selectedId, root.dataset.selectedName);
    }

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
                e.preventDefault();
            }
        } else if (e.key === 'Escape') {
            if (!dropdown.hidden) {
                e.preventDefault();
                closeDropdown();
            }
        }
    });

    clearBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        clearSelection();
    });

    document.addEventListener('click', (e) => {
        if (!root.contains(e.target)) {
            closeDropdown();
        }
    });

    async function fetchAndRender(query) {
        lastQuery = query;
        const requestToken = ++activeRequestToken;

        try {
            const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`org search failed: ${response.status}`);
            }

            const results = await response.json();

            if (requestToken !== activeRequestToken) return;

            currentResults = Array.isArray(results) ? results : [];
            renderResults(query);
        } catch (err) {
            console.warn('[org-picker] Search request failed:', err);
            if (requestToken !== activeRequestToken) return;
            currentResults = [];
            renderResults(query);
        }
    }

    function renderResults(query) {
        dropdown.innerHTML = '';

        // Check if the typed query exactly matches an existing result
        // (case-insensitive). If so, don't show the "create" option.
        const lowerQuery = query.toLowerCase();
        const exactMatch = currentResults.some(
            (row) => row.name.toLowerCase() === lowerQuery
        );

        if (currentResults.length === 0 && !query) {
            closeDropdown();
            return;
        }

        let idx = 0;

        // Existing org results.
        currentResults.forEach((row) => {
            const li = document.createElement('li');
            li.className = 'org-picker-result';
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.dataset.index = String(idx);

            const nameEl = document.createElement('div');
            nameEl.className = 'org-picker-result-name';
            nameEl.textContent = row.name;
            li.appendChild(nameEl);

            // Context line: type and/or tagline.
            const contextParts = [];
            if (row.type) contextParts.push(row.type);
            if (row.tagline) contextParts.push(row.tagline);

            if (contextParts.length > 0) {
                const contextEl = document.createElement('div');
                contextEl.className = 'org-picker-result-context';
                contextEl.textContent = contextParts.join(' · ');
                li.appendChild(contextEl);
            }

            li.addEventListener('click', () => {
                applySelection(row.id, row.name);
                closeDropdown();
            });

            li.addEventListener('mouseenter', () => setHighlight(Number(li.dataset.index)));

            dropdown.appendChild(li);
            idx++;
        });

        // "Create as prospect" option — shown when the typed name
        // doesn't exactly match any result.
        if (query && !exactMatch) {
            const li = document.createElement('li');
            li.className = 'org-picker-result org-picker-result--create';
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.dataset.index = String(idx);

            const label = document.createElement('div');
            label.className = 'org-picker-result-name';
            label.innerHTML = '';
            // Build safely: "Create" + quoted name + "as prospect"
            const createText = document.createElement('span');
            createText.textContent = `Create "${query}" as new prospect`;
            label.appendChild(createText);
            li.appendChild(label);

            li.addEventListener('click', () => quickCreateOrg(query));
            li.addEventListener('mouseenter', () => setHighlight(Number(li.dataset.index)));

            dropdown.appendChild(li);
            idx++;
        }

        if (dropdown.children.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'org-picker-result org-picker-result--empty';
            empty.setAttribute('role', 'option');
            empty.setAttribute('aria-disabled', 'true');
            empty.textContent = 'No matches.';
            dropdown.appendChild(empty);
        }

        highlightedIndex = 0;
        applyHighlight();
        openDropdown();
    }

    async function quickCreateOrg(name) {
        if (!quickStoreUrl) {
            console.warn('[org-picker] No quick-store URL configured.');
            return;
        }

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(quickStoreUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ name }),
            });

            if (!response.ok) {
                throw new Error(`quick-create failed: ${response.status}`);
            }

            const data = await response.json();
            applySelection(data.id, data.name);
            closeDropdown();
        } catch (err) {
            console.warn('[org-picker] Quick-create failed:', err);
        }
    }

    function applySelection(id, name) {
        hiddenInput.value = id;
        selectedDisplay.textContent = name;
        root.classList.add('has-selection');
        input.value = '';
    }

    function clearSelection() {
        hiddenInput.value = '';
        selectedDisplay.textContent = '';
        root.classList.remove('has-selection');
        input.value = '';
        input.focus();
    }

    function moveHighlight(delta) {
        const items = dropdown.querySelectorAll('[data-index]');
        if (items.length === 0) return;
        highlightedIndex = (highlightedIndex + delta + items.length) % items.length;
        applyHighlight();
    }

    function setHighlight(idx) {
        highlightedIndex = idx;
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
    document.querySelectorAll('[data-org-picker]').forEach(initOrgPicker);
});