@extends('layouts.app')

@section('title', ($draft->isEditing() ? 'Edit draft' : ($draft->isFormatted() ? 'Resume' : 'Approved draft')) . ' · ' . $jobListing->role_title)

@section('content')
    <div class="mb-2">
        <a href="{{ route('job-listings.show', $jobListing) }}" class="link-subtle text-sm">
            ← {{ $jobListing->role_title }} at {{ $jobListing->organization?->name ?? 'Unknown' }}
        </a>
    </div>

    <div class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                @if ($draft->isEditing())
                    Edit resume draft
                @elseif ($draft->isFormatted())
                    Resume
                @else
                    Approved draft
                @endif
            </h1>
            <p class="text-sm mt-1" style="color: var(--color-text-muted);">
                @if ($draft->isEditing())
                    Review and edit the AI-generated resume. When you're satisfied, approve it.
                @elseif ($draft->isApproved())
                    Approve looks good. Generate a formatted document to download.
                @else
                    Your formatted resume is ready for download.
                @endif
            </p>
        </div>

        @php
            $badgeLabel = match (true) {
                $draft->isEditing() => 'Editing',
                $draft->isApproved() => 'Approved',
                $draft->isFormatted() => 'Formatted',
                default => $draft->status,
            };
            $badgeIsSuccess = $draft->isApproved() || $draft->isFormatted();
        @endphp
        <span
            class="text-xs font-medium px-2 py-1 rounded shrink-0 mt-1"
            style="background: {{ $badgeIsSuccess ? 'var(--color-status-bg)' : 'var(--color-error-bg)' }};
                   color: {{ $badgeIsSuccess ? 'var(--color-accent)' : 'var(--color-error)' }};
                   border: 1px solid {{ $badgeIsSuccess ? 'var(--color-status-border)' : 'var(--color-error-border)' }};"
        >
            {{ $badgeLabel }}
        </span>
    </div>

    {{-- Error flash — not in the layout, shown inline here --}}
    @if (session('error'))
        <div
            class="mb-6 px-4 py-3 rounded-md text-sm"
            style="background: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error);"
        >
            {{ session('error') }}
        </div>
    @endif

    {{-- Strategy summary — collapsible context --}}
    <details class="mb-6">
        <summary
            class="text-sm font-medium cursor-pointer select-none"
            style="color: var(--color-text-secondary);"
        >
            Strategy summary
        </summary>
        <div
            class="mt-2 rounded-lg border p-4 text-sm leading-relaxed"
            style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
        >
            {{ $draft->strategy_summary }}
        </div>
    </details>

    {{-- Main editor --}}
    @if ($draft->isEditing())
        <form method="POST" action="{{ route('resume-drafts.update-content', $draft) }}">
            @csrf

            <div class="mb-4">
                <label for="user_content" class="field-label mb-1">Resume content (markdown)</label>
                <textarea
                    id="user_content"
                    name="user_content"
                    class="input @error('user_content') has-error @enderror"
                    rows="28"
                    style="font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, monospace; font-size: 0.8125rem; line-height: 1.5; resize: vertical;"
                >{{ old('user_content', $draft->user_content) }}</textarea>
                @error('user_content')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center justify-between gap-4 pt-4" style="border-top: 1px solid var(--color-surface-input-border);">
                <div class="flex items-center gap-3">
                    <button type="submit" class="btn-primary">
                        Save draft
                    </button>

                    <button
                        type="button"
                        class="btn-secondary"
                        data-revert-trigger
                    >
                        Revert to AI original
                    </button>
                </div>

                <button
                    type="button"
                    class="btn-primary"
                    data-approve-trigger
                >
                    Approve draft →
                </button>
            </div>
        </form>

        {{-- Revert confirmation modal --}}
        <div class="modal-overlay" data-revert-modal inert>
            <div class="modal-backdrop" data-revert-backdrop aria-hidden="true"></div>
            <div class="modal-panel">
                <h3 class="modal-title">Revert to AI original?</h3>
                <p class="modal-message">
                    This will discard your edits and restore the AI-generated version. You can continue editing after reverting.
                </p>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" data-revert-cancel>Cancel</button>
                    <form method="POST" action="{{ route('resume-drafts.revert', $draft) }}">
                        @csrf
                        <button type="submit" class="btn-destructive">Revert</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Approve confirmation modal --}}
        <div class="modal-overlay" data-approve-modal inert>
            <div class="modal-backdrop" data-approve-backdrop aria-hidden="true"></div>
            <div class="modal-panel">
                <h3 class="modal-title">Approve this draft?</h3>
                <p class="modal-message">
                    Approving locks the content for formatting. Make sure you're happy with the text before proceeding.
                </p>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" data-approve-cancel>Cancel</button>
                    <form method="POST" action="{{ route('resume-drafts.approve', $draft) }}">
                        @csrf
                        <button type="submit" class="btn-primary">Approve</button>
                    </form>
                </div>
            </div>
        </div>
    @else
        {{-- Approved / Formatted: read-only content display --}}
        <div class="mb-4">
            <h2 class="field-label mb-2">Resume content</h2>
            <div
                class="rounded-lg border p-5 text-sm leading-relaxed"
                style="border-color: var(--color-surface-input-border); background: var(--color-surface-input); font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, monospace; font-size: 0.8125rem; line-height: 1.5; white-space: pre-wrap; overflow-x: auto;"
            >{{ $draft->user_content }}</div>
        </div>

        {{-- Document generation & downloads --}}
        <div class="pt-4 mt-4" style="border-top: 1px solid var(--color-surface-input-border);">
            @if ($draft->artifacts->isNotEmpty())
                <h2 class="field-label mb-3">Generated documents</h2>
                <div class="space-y-2 mb-4">
                    @foreach ($draft->artifacts as $artifact)
                        <div
                            class="flex items-center justify-between rounded-lg border p-3"
                            style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                        >
                            <div class="text-sm">
                                <span class="font-medium">.{{ $artifact->file_format }}</span>
                                <span style="color: var(--color-text-muted);">
                                    · {{ number_format($artifact->file_size_bytes / 1024, 0) }} KB
                                    · {{ $artifact->created_at->format('M j, Y g:ia') }}
                                </span>
                            </div>
                            <a
                                href="{{ route('resume-drafts.download-artifact', [$draft, $artifact]) }}"
                                class="btn-primary text-sm"
                            >
                                Download
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('resume-drafts.generate-document', $draft) }}">
                @csrf
                <div class="flex items-end gap-3 flex-wrap">
                    <div>
                        <label for="candidate_name" class="field-label mb-1">Your name</label>
                        <input
                            type="text"
                            id="candidate_name"
                            name="candidate_name"
                            class="input @error('candidate_name') has-error @enderror"
                            value="{{ old('candidate_name') }}"
                            placeholder="Full name for the resume header"
                            required
                            style="min-width: 260px;"
                        >
                        @error('candidate_name')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="btn-primary">
                        {{ $draft->artifacts->isNotEmpty() ? 'Regenerate .docx' : 'Generate .docx' }}
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Revise selections — available in both editing and approved states --}}
    <div class="mt-6">
        <button
            type="button"
            class="link-subtle text-sm"
            data-revise-trigger
        >
            ← Revise selections and regenerate
        </button>
    </div>

    {{-- Revise selections confirmation modal --}}
    <div class="modal-overlay" data-revise-modal inert>
        <div class="modal-backdrop" data-revise-backdrop aria-hidden="true"></div>
        <div class="modal-panel">
            <h3 class="modal-title">Revise selections?</h3>
            <p class="modal-message">
                This will discard the generated draft and return you to the selection wizard. Your requirement decisions, catalog selections, and relevance notes are preserved — you can adjust them and regenerate.
            </p>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-revise-cancel>Cancel</button>
                <form method="POST" action="{{ route('resume-drafts.revise-selections', $draft) }}">
                    @csrf
                    <button type="submit" class="btn-destructive">Discard draft & revise</button>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal controller — handles revert, approve, and revise confirmation dialogs --}}
    <script>
        (function () {
            function setupModal(triggerAttr, modalAttr, backdropAttr, cancelAttr) {
                var trigger = document.querySelector('[' + triggerAttr + ']');
                var modal = document.querySelector('[' + modalAttr + ']');
                var backdrop = document.querySelector('[' + backdropAttr + ']');
                var cancel = document.querySelector('[' + cancelAttr + ']');

                if (!trigger || !modal) return;

                function open() {
                    modal.classList.add('is-open');
                    modal.removeAttribute('inert');
                    document.body.style.overflow = 'hidden';
                }

                function close() {
                    modal.classList.remove('is-open');
                    modal.setAttribute('inert', '');
                    document.body.style.overflow = '';
                    trigger.focus();
                }

                trigger.addEventListener('click', open);
                if (backdrop) backdrop.addEventListener('click', close);
                if (cancel) cancel.addEventListener('click', close);

                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                        close();
                    }
                });
            }

            setupModal('data-revert-trigger', 'data-revert-modal', 'data-revert-backdrop', 'data-revert-cancel');
            setupModal('data-approve-trigger', 'data-approve-modal', 'data-approve-backdrop', 'data-approve-cancel');
            setupModal('data-revise-trigger', 'data-revise-modal', 'data-revise-backdrop', 'data-revise-cancel');
        })();
    </script>
@endsection