/**
 * Requirement triage page behavior (Screen 1).
 *
 * Three concerns:
 *   1. Accept/Skip buttons on requirement cards
 *   2. Strategy summary save and revert (same pattern as selection-review.js)
 *   3. Progress tracking and continue-button gating
 *
 * Auto-mounts on every `[data-requirement-triage]` element.
 */

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-requirement-triage]').forEach(initRequirementTriage);
});

function initRequirementTriage(root) {
    const decidedCountEl = document.querySelector('[data-triage-decided-count]');
    const barFill = document.querySelector('[data-triage-progressbar-fill]');
    const barContainer = document.querySelector('[data-triage-progressbar]');
    const totalCount = barContainer ? parseInt(barContainer.dataset.total, 10) : 0;
    const continueBtn = root.querySelector('[data-triage-continue]');
    const hintEl = root.querySelector('[data-triage-hint]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    // Track decisions client-side for progress and continue-button state.
    const decisions = {};
    root.querySelectorAll('[data-triage-card]').forEach(card => {
        const id = card.dataset.requirementId;
        const decision = card.dataset.decision;
        if (decision) decisions[id] = decision;
    });

    // ── Accept / Skip buttons ─────────────────────────────────

    root.addEventListener('click', (e) => {
        const actionBtn = e.target.closest('[data-triage-action]');
        if (!actionBtn) return;

        const card = actionBtn.closest('[data-triage-card]');
        if (!card) return;
        if (actionBtn.disabled) return;

        e.preventDefault();
        handleDecision(card, actionBtn.dataset.triageAction);
    });

    async function handleDecision(card, decision) {
        const url = card.dataset.decideUrl;
        const requirementId = card.dataset.requirementId;
        if (!url) return;

        const previousDecision = card.dataset.decision;
        const wasPreviouslyUndecided = !previousDecision;

        // Optimistic UI update.
        applyDecisionState(card, decision);
        decisions[requirementId] = decision;
        if (wasPreviouslyUndecided) updateProgress(1);
        updateContinueButton();

        try {
            const data = await postJson(url, { decision });
            if (!data.ok) throw new Error(data.error || 'Decision failed');
            hideError(card);
        } catch (err) {
            console.warn('[requirement-triage] Decision failed:', err);
            // Revert on failure.
            applyDecisionState(card, previousDecision || '');
            if (previousDecision) {
                decisions[requirementId] = previousDecision;
            } else {
                delete decisions[requirementId];
            }
            if (wasPreviouslyUndecided) updateProgress(-1);
            updateContinueButton();
            showError(card, 'Action failed — please try again.');
        }
    }

    function applyDecisionState(card, decision) {
        card.dataset.decision = decision;

        card.classList.toggle('triage-card--accepted', decision === 'accepted');
        card.classList.toggle('triage-card--rejected', decision === 'rejected');

        const acceptBtn = card.querySelector('[data-triage-action="accepted"]');
        const skipBtn = card.querySelector('[data-triage-action="rejected"]');
        if (acceptBtn) acceptBtn.disabled = (decision === 'accepted');
        if (skipBtn) skipBtn.disabled = (decision === 'rejected');

        const acceptedBadge = card.querySelector('[data-triage-badge="accepted"]');
        const rejectedBadge = card.querySelector('[data-triage-badge="rejected"]');
        if (acceptedBadge) acceptedBadge.hidden = (decision !== 'accepted');
        if (rejectedBadge) rejectedBadge.hidden = (decision !== 'rejected');
    }

    // ── Progress tracking ─────────────────────────────────────

    function updateProgress(delta) {
        if (!decidedCountEl) return;
        const current = parseInt(decidedCountEl.textContent, 10) || 0;
        const next = Math.max(0, Math.min(totalCount, current + delta));
        decidedCountEl.textContent = next;

        if (barFill && totalCount > 0) {
            barFill.style.width = `${Math.round((next / totalCount) * 100)}%`;
        }
    }

    function updateContinueButton() {
        const decidedKeys = Object.keys(decisions);
        const allDecided = decidedKeys.length >= totalCount;
        const acceptedCount = Object.values(decisions).filter(d => d === 'accepted').length;
        const canContinue = allDecided && acceptedCount > 0;

        if (continueBtn) {
            if (canContinue) {
                continueBtn.classList.remove('opacity-50', 'pointer-events-none');
                continueBtn.removeAttribute('aria-disabled');
                continueBtn.removeAttribute('tabindex');

                // Update the href to point to the first accepted requirement.
                const firstAccepted = root.querySelector(
                    '[data-triage-card][data-decision="accepted"]'
                );
                if (firstAccepted) {
                    // Build the URL from the decide URL pattern: replace
                    // /decide with nothing to get the requirement show URL.
                    const decideUrl = firstAccepted.dataset.decideUrl;
                    if (decideUrl) {
                        continueBtn.href = decideUrl.replace(/\/decide$/, '');
                    }
                }
            } else {
                continueBtn.classList.add('opacity-50', 'pointer-events-none');
                continueBtn.setAttribute('aria-disabled', 'true');
                continueBtn.setAttribute('tabindex', '-1');
                continueBtn.href = '#';
            }
        }

        if (hintEl) {
            if (allDecided && acceptedCount > 0) {
                const label = acceptedCount === 1 ? 'requirement' : 'requirements';
                hintEl.textContent = `${acceptedCount} ${label} accepted — ready to review selections.`;
            } else if (allDecided && acceptedCount === 0) {
                hintEl.textContent = 'Accept at least one requirement to continue.';
            } else {
                hintEl.textContent = 'Decide on all requirements to continue.';
            }
        }
    }

    // ── Strategy summary ──────────────────────────────────────

    const strategyEditor = root.querySelector('[data-strategy-editor]');
    if (strategyEditor) {
        const strategyUrl = strategyEditor.dataset.strategyUrl;
        const synthesizeUrl = strategyEditor.dataset.strategySynthesizeUrl;
        const originalText = strategyEditor.dataset.strategyOriginal;
        const strategyInput = strategyEditor.querySelector('[data-strategy-input]');
        const saveBtn = strategyEditor.querySelector('[data-strategy-save]');
        const revertBtn = strategyEditor.querySelector('[data-strategy-revert]');
        const synthesizeBtn = strategyEditor.querySelector('[data-strategy-synthesize]');
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
                console.warn('[requirement-triage] Strategy save failed:', err);
                showStatus(statusEl, 'Save failed — try again');
            }
        });

        revertBtn?.addEventListener('click', () => {
            if (originalText !== undefined) {
                strategyInput.value = originalText;
                showStatus(statusEl, 'Reverted — click Save to confirm');
            }
        });

        synthesizeBtn?.addEventListener('click', async () => {
            const userText = strategyInput.value.trim();
            if (!userText && !originalText) return;

            synthesizeBtn.disabled = true;
            showStatus(statusEl, 'Synthesizing…');

            try {
                const data = await postJson(synthesizeUrl, {
                    ai_strategy: originalText || '',
                    user_strategy: userText || '',
                });
                if (!data.ok) throw new Error(data.error || 'Synthesis failed');

                strategyInput.value = data.synthesized;
                showStatus(statusEl, 'Synthesized — review and click Save to keep');
            } catch (err) {
                console.warn('[requirement-triage] Strategy synthesis failed:', err);
                showStatus(statusEl, 'Synthesis failed — try again');
            } finally {
                synthesizeBtn.disabled = false;
            }
        });
    }

    // ── Shared helpers ────────────────────────────────────────

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
        const el = card.querySelector('[data-triage-error]');
        if (el) { el.textContent = message; el.hidden = false; }
    }

    function hideError(card) {
        const el = card.querySelector('[data-triage-error]');
        if (el) el.hidden = true;
    }

    function showStatus(el, text) {
        if (!el) return;
        el.textContent = text;
        el.hidden = false;
    }

    function fadeStatus(el, delay = 2000) {
        if (!el) return;
        setTimeout(() => { el.hidden = true; }, delay);
    }
}