/**
 * People review page behavior.
 *
 * Auto-mounts on every `[data-people-review]` element. Same shape as
 * tag-review.js minus the alias picker — two actions (accept / reject)
 * instead of three. The postAction helper, counter management, and
 * Next button logic are identical.
 */

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-people-review]').forEach(initPeopleReview);
});

function initPeopleReview(root) {
    const nextButton = root.querySelector('[data-people-review-next]');
    // Progress bar elements live outside [data-people-review] in the DOM
    // (they're in the page header), so search document, not root.
    const reviewedCountEl = document.querySelector('[data-people-review-reviewed-count]');
    const progressbarFill = document.querySelector('[data-people-review-progressbar-fill]');
    const progressbarEl = document.querySelector('[data-people-review-progressbar]');
    const records = root.querySelectorAll('[data-people-review-record]');

    const totalCount = records.length;
    let pendingCount = 0;
    records.forEach((recordEl) => {
        if (recordEl.dataset.status === 'pending') {
            pendingCount += 1;
        } else {
            recordEl.dataset.decidedOnce = 'true';
        }
    });
    let reviewedCount = totalCount - pendingCount;
    updateCounter();
    updateNextButton();

    records.forEach((recordEl) => {
        const acceptBtn = recordEl.querySelector('[data-action="accept"]');
        const rejectBtn = recordEl.querySelector('[data-action="reject"]');

        acceptBtn?.addEventListener('click', (e) => {
            e.preventDefault();
            const url = recordEl.dataset.acceptUrl;
            postAction(url, {})
                .then((response) => {
                    markRecordDecided(recordEl, 'confirmed', {
                        matchPersonName: response.catalog_person_name,
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
    });

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
     * Apply the "decided" visual state to a record. Same pattern as
     * tag review — update card border, show the right status pill,
     * update the counter on first decision.
     *
     * Detail options:
     *   matchPersonName: for 'confirmed' status, the catalog person's
     *     display name as returned from the server (may differ from
     *     extracted_name if find-or-create matched an existing person
     *     with different casing).
     */
    function markRecordDecided(recordEl, status, detail = {}) {
        recordEl.dataset.status = status;
        clearRecordError(recordEl);

        recordEl.classList.remove('tag-review-card--approved', 'tag-review-card--rejected');
        if (status === 'confirmed') {
            recordEl.classList.add('tag-review-card--approved');
        } else if (status === 'rejected') {
            recordEl.classList.add('tag-review-card--rejected');
        }

        recordEl.querySelectorAll('[data-people-review-status-badge]').forEach((el) => {
            el.hidden = el.dataset.statusBadge !== status;
        });

        if (status === 'confirmed' && detail.matchPersonName) {
            const targetEl = recordEl.querySelector('[data-people-review-accept-target]');
            if (targetEl) targetEl.textContent = detail.matchPersonName;
        }

        if (!recordEl.dataset.decidedOnce) {
            recordEl.dataset.decidedOnce = 'true';
            pendingCount = Math.max(0, pendingCount - 1);
            reviewedCount += 1;
            updateCounter();
            updateNextButton();
        }
    }

    function showRecordError(recordEl, message) {
        const errorEl = recordEl.querySelector('[data-people-review-error]');
        if (!errorEl) {
            console.error('[people-review]', message);
            return;
        }
        errorEl.textContent = message;
        errorEl.hidden = false;
    }

    function clearRecordError(recordEl) {
        const errorEl = recordEl.querySelector('[data-people-review-error]');
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