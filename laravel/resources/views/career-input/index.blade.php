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
         creates the document, generates a title via AI (for pasted
         text) or derives one from the filename (for uploads), then
         redirects to the preview page where the user confirms the
         extraction. --}}
    <form action="{{ route('source-documents.store') }}" method="POST" enctype="multipart/form-data" class="mb-12" data-input-form>
        @csrf

        <div class="input-region" data-drop-zone>
            <div class="input-region-inner">
                {{-- Mode toggle is implicit: when a file is selected,
                     the textarea is replaced with a file preview. When
                     the file is removed, the textarea returns. The
                     mutual exclusion of body/upload is enforced by
                     server-side validation regardless of the UI state. --}}
                <div data-text-mode>
                    <label for="body" class="sr-only">Career notes</label>
                    <textarea
                        id="body"
                        name="body"
                        rows="12"
                        placeholder="Tell us about your career…"
                        class="input-textarea"
                    >{{ old('body') }}</textarea>
                </div>

                <div data-file-mode hidden>
                    <div class="input-file-preview">
                        <div class="input-file-preview-icon" aria-hidden="true">
                            <svg width="32" height="40" viewBox="0 0 32 40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round">
                                <path d="M3 1h18l8 8v29a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1z"/>
                                <path d="M21 1v8h8"/>
                            </svg>
                        </div>
                        <div class="input-file-preview-meta">
                            <p class="input-file-preview-name" data-file-name>filename.pdf</p>
                            <p class="input-file-preview-size" data-file-size>—</p>
                        </div>
                        <button
                            type="button"
                            class="input-file-preview-remove"
                            aria-label="Remove file"
                            data-file-remove
                        >
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <line x1="2" y1="2" x2="12" y2="12"/>
                                <line x1="12" y1="2" x2="2" y2="12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Hidden file input. The visible paperclip button
                     and drag-and-drop both pipe selected files into
                     this input so a normal form submission carries
                     the file along. --}}
                <input
                    type="file"
                    name="upload"
                    id="upload"
                    accept=".pdf,.txt,.md"
                    class="sr-only"
                    data-file-input
                >

                {{-- Drag overlay shown only while a file is being
                     dragged anywhere over the page. Sits on top of
                     the textarea/file preview so the user has
                     unambiguous "drop here" feedback. --}}
                <div class="input-drop-overlay" data-drop-overlay aria-hidden="true">
                    <p>Drop your file to upload</p>
                </div>
            </div>

            <div class="input-region-toolbar">
                <div class="input-region-toolbar-left">
                    <button
                        type="button"
                        class="paperclip-btn"
                        aria-label="Attach a file"
                        data-file-trigger
                    >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                        </svg>
                    </button>
                    <p class="input-region-hint">
                        <span data-hint-default>PDF, .txt, or .md — up to 10MB</span>
                        <span data-hint-active hidden>File ready to submit</span>
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
        @error('upload')
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

    {{-- Career-input page interactions: drag-and-drop, file selection,
         text/file mode toggling, and the loading overlay on submit.
         Plain DOM API, IIFE-scoped. --}}
    <script>
        (function () {
            const form = document.querySelector('[data-input-form]');
            const overlay = document.querySelector('[data-loading-overlay]');
            if (!form || !overlay) return;

            const submitBtn = form.querySelector('[data-submit-button]');
            const textarea = form.querySelector('textarea[name="body"]');
            const dropOverlay = form.querySelector('[data-drop-overlay]');
            const fileInput = form.querySelector('[data-file-input]');
            const fileTrigger = form.querySelector('[data-file-trigger]');
            const fileRemove = form.querySelector('[data-file-remove]');
            const textMode = form.querySelector('[data-text-mode]');
            const fileMode = form.querySelector('[data-file-mode]');
            const fileName = form.querySelector('[data-file-name]');
            const fileSize = form.querySelector('[data-file-size]');
            const hintDefault = form.querySelector('[data-hint-default]');
            const hintActive = form.querySelector('[data-hint-active]');

            // Submit-button enabled state. Enabled when the textarea
            // has content OR a file is selected. Updated on every
            // input change and when files are added/removed.
            function syncSubmitState() {
                const hasText = textarea.value.trim() !== '';
                const hasFile = fileInput.files && fileInput.files.length > 0;
                submitBtn.disabled = !hasText && !hasFile;
            }
            syncSubmitState();
            textarea.addEventListener('input', syncSubmitState);

            // Open the file picker when the paperclip is clicked.
            fileTrigger.addEventListener('click', () => fileInput.click());

            // When a file is selected via the picker, swap to file mode.
            fileInput.addEventListener('change', () => {
                if (fileInput.files && fileInput.files[0]) {
                    showFile(fileInput.files[0]);
                }
            });

            // Remove the attached file and revert to text mode.
            fileRemove.addEventListener('click', () => {
                fileInput.value = '';
                textMode.hidden = false;
                fileMode.hidden = true;
                hintDefault.hidden = false;
                hintActive.hidden = true;
                syncSubmitState();
                textarea.focus();
            });

            // Page-wide drag tracking. We count enter/leave events
            // because dragenter/dragleave fire on every child element,
            // which would make a naive show/hide flicker. The depth
            // counter is the canonical fix.
            let dragDepth = 0;

            ['dragenter', 'dragover'].forEach(evt => {
                document.addEventListener(evt, (e) => {
                    if (!hasFiles(e)) return;
                    e.preventDefault();
                    dragDepth++;
                    dropOverlay.classList.add('is-visible');
                });
            });

            ['dragleave', 'drop'].forEach(evt => {
                document.addEventListener(evt, (e) => {
                    if (!hasFiles(e)) return;
                    e.preventDefault();
                    dragDepth = Math.max(0, dragDepth - 1);
                    if (dragDepth === 0) {
                        dropOverlay.classList.remove('is-visible');
                    }
                });
            });

            // Handle the actual drop. Capture the file, populate the
            // hidden file input (so normal form submission carries
            // it along), reset the drag counter.
            document.addEventListener('drop', (e) => {
                if (!hasFiles(e)) return;
                e.preventDefault();
                dragDepth = 0;
                dropOverlay.classList.remove('is-visible');

                const file = e.dataTransfer.files[0];
                if (!file) return;

                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                showFile(file);
            });

            // Show the loading overlay on submit. The overlay stays
            // visible until the browser navigates to the response.
            form.addEventListener('submit', () => {
                overlay.classList.add('is-visible');
                overlay.removeAttribute('aria-hidden');
            });

            function hasFiles(e) {
                return e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
            }

            function showFile(file) {
                fileName.textContent = file.name;
                fileSize.textContent = formatSize(file.size);
                textMode.hidden = true;
                fileMode.hidden = false;
                hintDefault.hidden = true;
                hintActive.hidden = false;
                syncSubmitState();
            }

            function formatSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }
        })();
    </script>
@endsection