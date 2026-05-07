@extends('layouts.app')

@section('title', 'Career Input — Success')

@section('content')
    <div class="mb-10">
        <h1 class="text-3xl font-semibold tracking-tight mb-2">Career Input</h1>
        <p class="text-sm" style="color: var(--color-text-secondary);">
            Paste your career notes — interview prep, performance reviews, brag docs, journals — or drop a file.
            We'll extract structured records you can review and add to your catalog.
        </p>
    </div>

    {{-- The submission form. action="#" because the store handler
         doesn't exist yet (slice 3 will wire it up). The form still
         renders interactively so the UX can be evaluated. --}}
    <form action="#" method="POST" enctype="multipart/form-data" class="mb-12" data-input-form>
        @csrf

        {{-- Page-wide drag detection turns this whole region into a
             drop target. The drop zone gets the .is-drag-over class
             when a file is being dragged anywhere over the form. --}}
        <div class="input-region" data-drop-zone>
            <div class="input-region-inner">
                {{-- Mode toggle is implicit: when a file is selected,
                     the textarea is replaced with a file preview. When
                     the file is removed, the textarea returns. --}}
                <div data-text-mode>
                    <label for="body" class="sr-only">Career notes</label>
                    <textarea
                        id="body"
                        name="body"
                        rows="12"
                        placeholder="Paste your notes here…"
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

                {{-- Hidden actual file input. Triggered by the
                     paperclip button or by drag-and-drop on the
                     drop zone. --}}
                <input
                    type="file"
                    name="upload"
                    id="upload"
                    accept=".pdf,.txt,.md"
                    class="sr-only"
                    data-file-input
                >

                {{-- Drag overlay shown only while dragging. Sits on
                     top of the textarea/file preview so the user has
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
                <button type="submit" class="btn-primary">
                    Extract records
                </button>
            </div>
        </div>
    </form>

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
         text/file mode toggling. Plain DOM API, IIFE-scoped. --}}
    <script>
        (function () {
            const form = document.querySelector('[data-input-form]');
            if (!form) return;

            const dropZone = form.querySelector('[data-drop-zone]');
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
            const textarea = form.querySelector('textarea[name="body"]');

            // Open file picker when the paperclip is clicked.
            fileTrigger.addEventListener('click', () => fileInput.click());

            // When a file is selected via the picker.
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
                textarea.focus();
            });

            // Page-wide drag tracking. We count enter/leave events
            // because dragenter/dragleave fire on every child element,
            // making naive show/hide flicker. The counter approach is
            // the canonical fix.
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

            // The actual drop. Capture the file, reset the counter,
            // hide the overlay, populate the file input and preview.
            document.addEventListener('drop', (e) => {
                if (!hasFiles(e)) return;
                e.preventDefault();
                dragDepth = 0;
                dropOverlay.classList.remove('is-visible');

                const file = e.dataTransfer.files[0];
                if (!file) return;

                // Mirror the file into the hidden file input so the
                // form sees it on submit.
                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                showFile(file);
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
            }

            function formatSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }
        })();
    </script>
@endsection