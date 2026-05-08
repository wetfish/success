@extends('layouts.app')

@section('title', 'Career Input — Success')

@section('content')
    <div class="mb-10">
        <h1 class="text-3xl font-semibold tracking-tight mb-2">Career Input</h1>
        <p class="text-sm" style="color: var(--color-text-secondary);">
            Enter information about your career and job experience. You can upload a resume, notes,
            interview prep, or performance reviews. We'll extract structured records you can review
            and add to your catalog.
        </p>
    </div>

    {{-- The submission form. Posts to source-documents.store which
         creates the document, generates a title via AI, and redirects
         to the preview page where the user confirms the extraction.
         File upload UI is rendered but disabled — submission with a
         file is not yet supported (next slice). --}}
    <form action="{{ route('source-documents.store') }}" method="POST" class="mb-12" data-input-form>
        @csrf

        <div class="input-region">
            <div class="input-region-inner">
                {{-- Text body input. Required for now since file
                     upload is deferred to the next slice. --}}
                <div>
                    <label for="body" class="sr-only">Career notes</label>
                    <textarea
                        id="body"
                        name="body"
                        rows="12"
                        placeholder="Tell us about your career…"
                        class="input-textarea"
                    >{{ old('body') }}</textarea>
                </div>
            </div>

            <div class="input-region-toolbar">
                <div class="input-region-toolbar-left">
                    <button
                        type="button"
                        class="paperclip-btn is-disabled"
                        aria-label="Attach a file (coming soon)"
                        title="File upload coming soon"
                        disabled
                    >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                        </svg>
                    </button>
                    <p class="input-region-hint">
                        File upload coming soon — paste your notes for now
                    </p>
                </div>
                <button type="submit" class="btn-primary" data-submit-button>
                    Continue
                </button>
            </div>
        </div>

        @error('body')
            <p class="field-error mt-2">{{ $message }}</p>
        @enderror
    </form>

    {{-- Loading overlay shown during the title generation step. The
         form-submit handler below makes it visible immediately on
         submit; it stays visible until the browser navigates to the
         preview page response. --}}
    <div class="loading-overlay" data-loading-overlay aria-hidden="true">
        <div class="loading-overlay-inner">
            <div class="loading-spinner" aria-hidden="true"></div>
            <p class="loading-message" data-loading-message>Reading your notes…</p>
        </div>
    </div>

    <div>
        <h2 class="section-heading mb-4">Previous submissions</h2>

        @if ($sourceDocuments->isEmpty())
            <div
                class="border border-dashed rounded-lg p-10 text-center"
                style="border-color: var(--color-surface-input-border);"
            >
                <p class="text-sm" style="color: var(--color-text-secondary);">
                    Documents you submit will appear here, with their extracted records ready for review.
                </p>
            </div>
        @else
            <ul
                class="rounded-lg overflow-hidden border"
                style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
            >
                @foreach ($sourceDocuments as $document)
                    <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                        <a href="{{ route('source-documents.show', $document) }}" class="list-row">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-medium truncate">
                                        {{ $document->title ?: 'Untitled document' }}
                                    </h3>
                                    @if ($document->context_notes)
                                        <p class="text-sm truncate mt-0.5" style="color: var(--color-text-secondary);">
                                            {{ $document->context_notes }}
                                        </p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 text-xs shrink-0" style="color: var(--color-text-muted);">
                                    @if ($document->file_type)
                                        <span class="uppercase tracking-wide">{{ $document->file_type }}</span>
                                    @endif
                                    <span>{{ $document->created_at->format('M j, Y') }}</span>
                                </div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Career-input page interactions. File upload handling is
         deferred to a later slice — for now the only client-side
         behavior is showing the loading overlay on form submit so
         the user has feedback during the title generation step. --}}
    <script>
        (function () {
            const form = document.querySelector('[data-input-form]');
            const overlay = document.querySelector('[data-loading-overlay]');
            const submitBtn = form?.querySelector('[data-submit-button]');
            const textarea = form?.querySelector('textarea[name="body"]');

            if (!form || !overlay || !submitBtn || !textarea) return;

            // Disable submit when the body is empty so the user can't
            // submit nothing and burn through validation cycles. This
            // is a UX nicety — the server still validates required.
            function syncSubmitState() {
                submitBtn.disabled = textarea.value.trim() === '';
            }
            syncSubmitState();
            textarea.addEventListener('input', syncSubmitState);

            form.addEventListener('submit', () => {
                overlay.classList.add('is-visible');
                overlay.removeAttribute('aria-hidden');
            });
        })();
    </script>
@endsection