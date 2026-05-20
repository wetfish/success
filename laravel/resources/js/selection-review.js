/**
 * Selection review page behavior.
 *
 * Three concerns:
 *   1. Include/Exclude toggles on selection cards (existing)
 *   2. Strategy summary save and revert (new)
 *   3. Per-selection relevance note save (new)
 *
 * Auto-mounts on every `[data-selection-review]` element.
 */

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-selection-review]').forEach(initSelectionReview);
});

function initSelectionReview(root) {
    const countEl = document.querySelector('[data-selection-count]');
    const barFill = document.querySelector('[data-selection-bar]');
    const totalCount = barFill ? parseInt(barFill.dataset.total, 10) : 0;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    // ── Include / Exclude toggles ────────────────────────────

    root.addEventListener('click', (e) => {
        const actionBtn = e.target.closest('[data-action]');
        if (!actionBtn) return;

        const card = actionBtn.closest('[data-selection-card]');
        if (!card) return;

        const action = actionBtn.dataset.action;
        if (action !== 'include' && action !== 'exclude') return;
        if (actionBtn.disabled) return;

        e.preventDefault();
        handleToggle(card, action);
    });

    async function handleToggle(card, action) {
        const url = card.dataset.toggleUrl;
        if (!url) return;

        const wasSelected = card.dataset.selected === 'true';
        const nowSelected = action === 'include';
        if (wasSelected === nowSelected) return;

        applyCardState(card, nowSelected);
        updateCounter(nowSelected ? 1 : -1);

        try {
            const data = await postJson(url);
            if (!data.ok) throw new Error(data.error || 'Toggle failed');
            applyCardState(card, data.selected);
            if (data.selected !== nowSelected) updateCounter(data.selected ? 1 : -1);
            hideError(card);
        } catch (err) {
            console.warn('[selection-review] Toggle failed:', err);
            applyCardState(card, wasSelected);
            updateCounter(wasSelected ? 1 : -1);
            showError(card, 'Action failed — please try again.');
        }
    }

    function applyCardState(card, isSelected) {
        card.dataset.selected = isSelected ? 'true' : 'false';
        card.classList.toggle('selection-card--included', isSelected);
        card.classList.toggle('selection-card--excluded', !isSelected);

        const includeBtn = card.querySelector('[data-action="include"]');
        const excludeBtn = card.querySelector('[data-action="exclude"]');
        if (includeBtn) includeBtn.disabled = isSelected;
        if (excludeBtn) excludeBtn.disabled = !isSelected;

        const includedBadge = card.querySelector('[data-selection-badge="included"]');
        const excludedBadge = card.querySelector('[data-selection-badge="excluded"]');
        if (includedBadge) includedBadge.hidden = !isSelected;
        if (excludedBadge) excludedBadge.hidden = isSelected;
    }

    // ── Strategy summary ─────────────────────────────────────

    const strategyEditor = root.querySelector('[data-strategy-editor]');
    if (strategyEditor) {
        const strategyUrl = strategyEditor.dataset.strategyUrl;
        const originalText = strategyEditor.dataset.strategyOriginal;
        const strategyInput = strategyEditor.querySelector('[data-strategy-input]');
        const saveBtn = strategyEditor.querySelector('[data-strategy-save]');
        const revertBtn = strategyEditor.querySelector('[data-strategy-revert]');
        const statusEl = strategyEditor.querySelector('[data-strategy-status]');

        saveBtn?.addEventListener('click', async () => {
            const text = strategyInput.value.trim();
            if (!text) return;

            showStatus(statusEl, 'Saving…');
            try {
                const data = await postJson(strategyUrl, { strategy_summary: text });
                if (!data.ok) throw new Error(data.error || 'Save failed');
                showStatus(statusEl, 'Saved');
                fadeStatus(statusEl);
            } catch (err) {
                console.warn('[selection-review] Strategy save failed:', err);
                showStatus(statusEl, 'Save failed — try again');
            }
        });

        revertBtn?.addEventListener('click', () => {
            if (originalText !== undefined) {
                strategyInput.value = originalText;
                showStatus(statusEl, 'Reverted — click Save to confirm');
            }
        });
    }

    // ── Relevance notes (auto-save + auto-resize) ──────────

    const NOTE_DEBOUNCE_MS = 800;
    const noteTimers = new Map();

    // Auto-resize: fit textarea height to content. Called on
    // input and once on mount for pre-populated notes.
    function autoResize(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
    }

    // Mount: auto-resize any pre-populated note textareas.
    root.querySelectorAll('[data-note-input]').forEach(autoResize);

    root.addEventListener('input', (e) => {
        const noteInput = e.target.closest('[data-note-input]');
        if (!noteInput) return;

        autoResize(noteInput);

        const card = noteInput.closest('[data-selection-card]');
        if (!card) return;

        const selectionId = card.dataset.selectionId;
        const statusEl = card.querySelector('[data-note-status]');
        showStatus(statusEl, 'Typing…');

        // Clear any pending save for this card.
        if (noteTimers.has(selectionId)) {
            clearTimeout(noteTimers.get(selectionId));
        }

        noteTimers.set(selectionId, setTimeout(() => {
            noteTimers.delete(selectionId);
            handleNoteSave(card);
        }, NOTE_DEBOUNCE_MS));
    });

    async function handleNoteSave(card) {
        const url = card.dataset.noteUrl;
        if (!url) return;

        const noteInput = card.querySelector('[data-note-input]');
        const statusEl = card.querySelector('[data-note-status]');
        if (!noteInput) return;

        const text = noteInput.value.trim() || null;
        showStatus(statusEl, 'Saving…');

        try {
            const data = await postJson(url, { user_relevance_note: text });
            if (!data.ok) throw new Error(data.error || 'Save failed');
            showStatus(statusEl, 'Saved');
            fadeStatus(statusEl);
        } catch (err) {
            console.warn('[selection-review] Note save failed:', err);
            showStatus(statusEl, 'Save failed — try again');
        }
    }

    // ── Shared helpers ───────────────────────────────────────

    async function postJson(url, body = null) {
        const options = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
            },
            credentials: 'same-origin',
        };

        if (body !== null) {
            options.body = JSON.stringify(body);
        }

        const response = await fetch(url, options);
        if (!response.ok) {
            throw new Error(`Request failed: ${response.status}`);
        }
        return response.json();
    }

    function showError(card, message) {
        const el = card.querySelector('[data-selection-error]');
        if (el) { el.textContent = message; el.hidden = false; }
    }

    function hideError(card) {
        const el = card.querySelector('[data-selection-error]');
        if (el) el.hidden = true;
    }

    function showStatus(el, text) {
        if (!el) return;
        el.textContent = text;
    }

    function fadeStatus(el, delay = 2000) {
        if (!el) return;
        setTimeout(() => { el.textContent = ''; }, delay);
    }

    function updateCounter(delta) {
        if (!countEl) return;
        const current = parseInt(countEl.textContent, 10) || 0;
        const next = Math.max(0, Math.min(totalCount, current + delta));
        countEl.textContent = next;

        if (barFill && totalCount > 0) {
            barFill.style.width = `${Math.round((next / totalCount) * 100)}%`;
        }
    }
}