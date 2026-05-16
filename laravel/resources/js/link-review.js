/**
 * Link review page behavior.
 *
 * Auto-mounts on every `[data-link-review]` element. Same structure as
 * tag-review.js and people-review.js — progress bar, counter, next
 * button, accept/reject actions — plus field editing (url, title,
 * type, description, is_personal_appearance) that saves on blur/change
 * via the update endpoint.
 */

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-link-review]').forEach(initLinkReview);
});

function initLinkReview(root) {
    const nextButton = root.querySelector('[data-link-review-next]');
    // Progress bar elements live outside [data-link-review] in the
    // page header, so search document, not root.
    const reviewedCountEl = document.querySelector('[data-link-review-reviewed-count]');
    const progressbarFill = document.querySelector('[data-link-review-progressbar-fill]');
    const progressbarEl = document.querySelector('[data-link-review-progressbar]');
    const records = root.querySelectorAll('[data-link-review-record]');

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

        // Accept / Reject actions
        acceptBtn?.addEventListener('click', (e) => {
            e.preventDefault();
            postAction(recordEl.dataset.acceptUrl, {})
                .then(() => markRecordDecided(recordEl, 'confirmed'))
                .catch((err) => showRecordError(recordEl, err.message));
        });

        rejectBtn?.addEventListener('click', (e) => {
            e.preventDefault();
            postAction(recordEl.dataset.rejectUrl, {})
                .then(() => markRecordDecided(recordEl, 'rejected'))
                .catch((err) => showRecordError(recordEl, err.message));
        });

        // Field editing — save on blur for text inputs/textareas,
        // on change for selects and checkboxes.
        recordEl.querySelectorAll('[data-field]').forEach((fieldEl) => {
            const fieldName = fieldEl.dataset.field;
            const isCheckbox = fieldEl.type === 'checkbox';
            const isSelect = fieldEl.tagName === 'SELECT';
            const event = (isCheckbox || isSelect) ? 'change' : 'blur';

            fieldEl.addEventListener(event, () => {
                const value = isCheckbox ? fieldEl.checked : fieldEl.value;
                const body = { [fieldName]: value };

                postAction(recordEl.dataset.updateUrl, body)
                    .then((response) => {
                        clearRecordError(recordEl);
                        if (fieldName === 'url' && response.payload) {
                            const displayEl = recordEl.querySelector('[data-link-review-url-display]');
                            if (displayEl) {
                                const newUrl = response.payload.url || '';
                                displayEl.textContent = newUrl || '(no url)';
                                displayEl.href = newUrl || '#';
                            }
                        }
                    })
                    .catch((err) => showRecordError(recordEl, err.message));
            });
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

    function markRecordDecided(recordEl, status) {
        recordEl.dataset.status = status;
        clearRecordError(recordEl);

        recordEl.classList.remove('tag-review-card--approved', 'tag-review-card--rejected');
        if (status === 'confirmed') {
            recordEl.classList.add('tag-review-card--approved');
        } else if (status === 'rejected') {
            recordEl.classList.add('tag-review-card--rejected');
        }

        recordEl.querySelectorAll('[data-link-review-status-badge]').forEach((el) => {
            el.hidden = el.dataset.statusBadge !== status;
        });

        if (!recordEl.dataset.decidedOnce) {
            recordEl.dataset.decidedOnce = 'true';
            pendingCount = Math.max(0, pendingCount - 1);
            reviewedCount += 1;
            updateCounter();
            updateNextButton();
        }
    }

    function showRecordError(recordEl, message) {
        const errorEl = recordEl.querySelector('[data-link-review-error]');
        if (!errorEl) {
            console.error('[link-review]', message);
            return;
        }
        errorEl.textContent = message;
        errorEl.hidden = false;
    }

    function clearRecordError(recordEl) {
        const errorEl = recordEl.querySelector('[data-link-review-error]');
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