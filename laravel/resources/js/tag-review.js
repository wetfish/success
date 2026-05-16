/**
 * Tag review page behavior.
 *
 * Auto-mounts on every `[data-tag-review]` element. Each page has
 * exactly one. The mount reads the page's per-record action URLs
 * from the DOM (each record's wrapper carries data attributes
 * pointing at its accept/reject/alias endpoints).
 *
 * Three action types, all with the same shape:
 *   - JS intercepts the button click, POSTs to the appropriate URL
 *   - Server returns {ok: true} or {error: '...'} with HTTP status
 *   - On ok: update the row's local DOM state (mark as decided,
 *     hide action buttons, decrement the pending counter, maybe
 *     enable the Next button)
 *   - On error: display the error message inline near the row,
 *     keep the row in its previous decision state so retry works
 *
 * The Alias action is two-step: clicking the Alias button opens an
 * alias picker (single instance, repositioned per click) below the
 * row. Picking a tag fires the alias POST with the selected
 * target_tag_id.
 *
 * No HTML fragments come back from the server — all DOM updates are
 * client-driven from the JSON ack. Per the slice's design contract.
 */

import { createAliasPicker } from './alias-picker.js';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-tag-review]').forEach(initTagReview);
});

function initTagReview(root) {
    const searchUrl = root.dataset.searchUrl;
    const nextButton = root.querySelector('[data-tag-review-next]');
    const reviewedCountEl = root.querySelector('[data-tag-review-reviewed-count]');
    const progressbarFill = root.querySelector('[data-tag-review-progressbar-fill]');
    const progressbarEl = root.querySelector('[data-tag-review-progressbar]');
    const records = root.querySelectorAll('[data-tag-review-record]');

    if (!searchUrl) {
        console.warn('[tag-review] Missing data-search-url; skipping init.');
        return;
    }

    // Initial counter state derives from the records' rendered status.
    // The Blade rendered each record's data-status to its persisted
    // value, so we can read directly without a server round-trip.
    // Records already marked decided-once on init don't transition the
    // counter on subsequent actions — only their first pending→decided
    // move counts as "newly reviewed."
    const totalCount = records.length;
    let pendingCount = 0;
    records.forEach((recordEl) => {
        if (recordEl.dataset.status === 'pending') {
            pendingCount += 1;
        } else {
            // Pre-decided records — flag so we don't increment the
            // reviewed counter when their button is clicked again.
            recordEl.dataset.decidedOnce = 'true';
        }
    });
    let reviewedCount = totalCount - pendingCount;
    updateCounter();
    updateNextButton();

    // Lazy-mount a single shared alias picker. It gets repositioned
    // into whichever record's alias slot is currently active. This
    // keeps memory/DOM footprint small even on pages with many records.
    let activeAliasRecord = null;
    let aliasPickerContainer = null;
    let aliasPickerHandle = null;

    function ensureAliasPicker(slotEl) {
        if (!aliasPickerContainer) {
            aliasPickerContainer = document.createElement('div');
            aliasPickerContainer.className = 'tag-picker alias-picker-host';
        }
        if (aliasPickerContainer.parentNode !== slotEl) {
            slotEl.appendChild(aliasPickerContainer);
        }
        aliasPickerContainer.hidden = false;

        if (!aliasPickerHandle) {
            aliasPickerHandle = createAliasPicker({
                container: aliasPickerContainer,
                searchUrl,
                onSelect: handleAliasPick,
                onCancel: closeAliasPicker,
            });
        } else {
            aliasPickerHandle.clear();
        }
        aliasPickerHandle.focus();
    }

    function closeAliasPicker() {
        activeAliasRecord = null;
        if (aliasPickerContainer) {
            aliasPickerContainer.hidden = true;
        }
        if (aliasPickerHandle) {
            aliasPickerHandle.clear();
        }
    }

    function handleAliasPick(targetTag) {
        if (!activeAliasRecord) return;
        const aliasUrl = activeAliasRecord.dataset.aliasUrl;
        const recordEl = activeAliasRecord;

        postAction(aliasUrl, { target_tag_id: targetTag.id })
            .then(() => {
                markRecordDecided(recordEl, 'merged', {
                    matchTagName: targetTag.name,
                });
                closeAliasPicker();
            })
            .catch((err) => {
                showRecordError(recordEl, err.message);
            });
    }

    // Wire action buttons on each record.
    records.forEach((recordEl) => {
        const acceptBtn = recordEl.querySelector('[data-action="accept"]');
        const rejectBtn = recordEl.querySelector('[data-action="reject"]');
        const aliasBtn = recordEl.querySelector('[data-action="alias"]');

        acceptBtn?.addEventListener('click', (e) => {
            e.preventDefault();
            const url = recordEl.dataset.acceptUrl;
            postAction(url, {})
                .then((response) => {
                    markRecordDecided(recordEl, 'confirmed', {
                        matchTagName: response.catalog_tag_name,
                    });
                })
                .catch((err) => showRecordError(recordEl, err.message));
        });

        rejectBtn?.addEventListener('click', (e) => {
            e.preventDefault();
            const url = recordEl.dataset.rejectUrl;
            postAction(url, {})
                .then(() => markRecordDecided(recordEl, 'rejected'))
                .catch((err) => showRecordError(recordEl, err.message));
        });

        aliasBtn?.addEventListener('click', (e) => {
            e.preventDefault();
            const slotEl = recordEl.querySelector('[data-tag-review-alias-slot]');
            if (!slotEl) {
                console.warn('[tag-review] Record missing alias slot.');
                return;
            }
            activeAliasRecord = recordEl;
            clearRecordError(recordEl);
            ensureAliasPicker(slotEl);
        });
    });

    /**
     * POST a JSON body to the given URL, returning a promise that
     * resolves on {ok: true} and rejects on {error: '...'} or any
     * non-2xx status. Includes CSRF token from the meta tag.
     */
    async function postAction(url, body) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body || {}),
        });

        let parsed;
        try {
            parsed = await response.json();
        } catch {
            parsed = null;
        }

        if (!response.ok || !parsed?.ok) {
            const message = parsed?.error || `Request failed (${response.status}).`;
            throw new Error(message);
        }

        return parsed;
    }

    /**
     * Apply the "decided" visual state to a record. Updates the card's
     * border tint, shows the right status pill, hides the others. The
     * action buttons stay visible so the user can re-decide.
     *
     * Detail options:
     *   matchTagName: for 'merged' status, the chosen target's display
     *     name. For 'confirmed' status, the catalog tag's display name
     *     as returned from the server (the catalog might have a slight
     *     casing variance from the extracted name if find-or-create
     *     matched an existing tag).
     */
    function markRecordDecided(recordEl, status, detail = {}) {
        recordEl.dataset.status = status;
        clearRecordError(recordEl);

        // Card border state. Three exclusive classes — pink for
        // approved (confirmed/merged), muted for rejected, default
        // (no extra class) for pending.
        recordEl.classList.remove('tag-review-card--approved', 'tag-review-card--rejected');
        if (status === 'confirmed' || status === 'merged') {
            recordEl.classList.add('tag-review-card--approved');
        } else if (status === 'rejected') {
            recordEl.classList.add('tag-review-card--rejected');
        }

        // Show the appropriate status indicator. The Blade renders the
        // wrappers for all three; we just unhide the right one.
        recordEl.querySelectorAll('[data-tag-review-status-badge]').forEach((el) => {
            el.hidden = el.dataset.statusBadge !== status;
        });

        // Populate the target name on the pill that's about to be
        // visible. Confirmed uses the catalog tag name (server-provided);
        // merged uses the alias target name (from the picker callback).
        if (status === 'confirmed' && detail.matchTagName) {
            const targetEl = recordEl.querySelector('[data-tag-review-accept-target]');
            if (targetEl) targetEl.textContent = detail.matchTagName;
        }
        if (status === 'merged' && detail.matchTagName) {
            const targetEl = recordEl.querySelector('[data-tag-review-merge-target]');
            if (targetEl) targetEl.textContent = detail.matchTagName;
        }

        // Close the alias picker if it was open on this record.
        if (activeAliasRecord === recordEl) {
            closeAliasPicker();
        }

        // Counter tracks transitions from pending to anything-else. Once
        // a record has transitioned out of pending, subsequent decisions
        // don't move the counter — re-deciding doesn't change "how many
        // things still need a first decision." We track this via a
        // per-record flag rather than reading status because status may
        // round-trip through different decided states.
        if (!recordEl.dataset.decidedOnce) {
            recordEl.dataset.decidedOnce = 'true';
            pendingCount = Math.max(0, pendingCount - 1);
            reviewedCount += 1;
            updateCounter();
            updateNextButton();
        }
    }

    function showRecordError(recordEl, message) {
        const errorEl = recordEl.querySelector('[data-tag-review-error]');
        if (!errorEl) {
            // No error slot — surface in console as a fallback.
            console.error('[tag-review]', message);
            return;
        }
        errorEl.textContent = message;
        errorEl.hidden = false;
    }

    function clearRecordError(recordEl) {
        const errorEl = recordEl.querySelector('[data-tag-review-error]');
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.hidden = true;
        }
    }

    function updateCounter() {
        if (reviewedCountEl) {
            reviewedCountEl.textContent = String(reviewedCount);
        }
        if (progressbarFill && totalCount > 0) {
            const percent = Math.round((reviewedCount / totalCount) * 100);
            progressbarFill.style.width = `${percent}%`;
            if (progressbarEl) {
                progressbarEl.setAttribute('aria-valuenow', String(percent));
            }
        }
    }

    function updateNextButton() {
        if (!nextButton) return;
        if (pendingCount === 0) {
            nextButton.classList.remove('is-disabled');
            nextButton.removeAttribute('aria-disabled');
            nextButton.removeAttribute('tabindex');
        } else {
            nextButton.classList.add('is-disabled');
            nextButton.setAttribute('aria-disabled', 'true');
            nextButton.setAttribute('tabindex', '-1');
        }
    }
}