/**
 * Selection review page behavior.
 *
 * Auto-mounts on every `[data-selection-review]` element. Each
 * selection card has Include and Exclude buttons — same pattern as
 * the tag review page. Clicking a button fires a POST to toggle
 * the `selected` boolean, then updates the card's visual state
 * (border tint, badge visibility, button disabled states).
 *
 * Counter and progress bar update on each action. Searches
 * `document` for counter elements since they live outside the
 * `[data-selection-review]` root.
 */

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-selection-review]').forEach(initSelectionReview);
});

function initSelectionReview(root) {
    const countEl = document.querySelector('[data-selection-count]');
    const barFill = document.querySelector('[data-selection-bar]');
    const totalCount = barFill ? parseInt(barFill.dataset.total, 10) : 0;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    root.addEventListener('click', (e) => {
        const actionBtn = e.target.closest('[data-action]');
        if (!actionBtn) return;

        const card = actionBtn.closest('[data-selection-card]');
        if (!card) return;

        const action = actionBtn.dataset.action;
        if (action !== 'include' && action !== 'exclude') return;

        // If the button is already disabled, this is the current state — no-op.
        if (actionBtn.disabled) return;

        e.preventDefault();
        handleAction(card, action);
    });

    async function handleAction(card, action) {
        const url = card.dataset.toggleUrl;
        if (!url) return;

        const wasSelected = card.dataset.selected === 'true';
        const nowSelected = action === 'include';

        // If clicking the same state, no-op.
        if (wasSelected === nowSelected) return;

        // Optimistic UI.
        applyState(card, nowSelected);
        updateCounter(nowSelected ? 1 : -1);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Toggle failed: ${response.status}`);
            }

            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.error || 'Toggle failed');
            }

            // Confirm server state.
            applyState(card, data.selected);

            // Fix counter if optimistic guess was wrong.
            if (data.selected !== nowSelected) {
                updateCounter(data.selected ? 1 : -1);
            }

            hideError(card);
        } catch (err) {
            console.warn('[selection-review] Toggle failed, reverting:', err);
            applyState(card, wasSelected);
            updateCounter(wasSelected ? 1 : -1);
            showError(card, 'Action failed — please try again.');
        }
    }

    function applyState(card, isSelected) {
        card.dataset.selected = isSelected ? 'true' : 'false';

        // Card border tint.
        card.classList.toggle('selection-card--included', isSelected);
        card.classList.toggle('selection-card--excluded', !isSelected);

        // Button disabled states — the active state's button is disabled.
        const includeBtn = card.querySelector('[data-action="include"]');
        const excludeBtn = card.querySelector('[data-action="exclude"]');
        if (includeBtn) includeBtn.disabled = isSelected;
        if (excludeBtn) excludeBtn.disabled = !isSelected;

        // Status badges.
        const includedBadge = card.querySelector('[data-selection-badge="included"]');
        const excludedBadge = card.querySelector('[data-selection-badge="excluded"]');
        if (includedBadge) includedBadge.hidden = !isSelected;
        if (excludedBadge) excludedBadge.hidden = isSelected;
    }

    function showError(card, message) {
        const errorEl = card.querySelector('[data-selection-error]');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.hidden = false;
        }
    }

    function hideError(card) {
        const errorEl = card.querySelector('[data-selection-error]');
        if (errorEl) {
            errorEl.hidden = true;
        }
    }

    function updateCounter(delta) {
        if (!countEl) return;
        const current = parseInt(countEl.textContent, 10) || 0;
        const next = Math.max(0, Math.min(totalCount, current + delta));
        countEl.textContent = next;

        if (barFill && totalCount > 0) {
            const pct = Math.round((next / totalCount) * 100);
            barFill.style.width = `${pct}%`;
        }
    }
}