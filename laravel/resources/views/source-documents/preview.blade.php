@extends('layouts.app')

@section('title', 'Preview submission — Success')

@section('content')
    <div class="mb-2">
        <a href="{{ route('career-input.index') }}" class="link-subtle text-sm">
            ← Career Input
        </a>
    </div>

    <div class="mb-8">
        <h1 class="text-3xl font-semibold tracking-tight mb-2">Ready to extract</h1>
        <p class="text-sm" style="color: var(--color-text-secondary);">
            Review your notes and the cost estimate before running extraction.
        </p>
    </div>

    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-5 mb-10">
        <div class="sm:col-span-2">
            <dt class="metadata-label">Title</dt>
            <dd class="mt-1 text-sm">
                {{ $sourceDocument->title ?: 'Untitled document' }}
            </dd>
        </div>

        <div>
            <dt class="metadata-label">Estimated cost</dt>
            <dd class="mt-1 text-sm">
                @if ($estimatedCostCents !== null)
                    <span class="font-medium">${{ $estimatedCostCents < 100 ? number_format($estimatedCostCents / 100, 4) : number_format($estimatedCostCents / 100, 2) }}</span>
                    <span class="text-xs" style="color: var(--color-text-muted);">
                        — {{ number_format($estimatedTokens) }} input tokens
                    </span>
                @else
                    <span style="color: var(--color-text-muted);">Estimate unavailable</span>
                @endif
            </dd>
        </div>
    </dl>

    <div class="mb-8">
        @if ($sourceDocument->isPdf())
            {{-- PDF documents have no body column content — the file
                 itself is the source. Embed it inline so the user can
                 verify what they uploaded before paying to extract.
                 The browser's built-in PDF renderer handles scrolling
                 and zoom; the download link is a fallback for users
                 whose browsers can't display PDFs inline. --}}
            <h2 class="section-heading mb-4">PDF preview</h2>
            <iframe
                src="{{ route('source-documents.file', $sourceDocument) }}"
                class="w-full rounded-lg border"
                style="height: 600px; background: var(--color-surface-input); border-color: var(--color-surface-input-border);"
                title="PDF preview"
            ></iframe>
            <p class="mt-2 text-xs" style="color: var(--color-text-muted);">
                Can't see the PDF?
                <a href="{{ route('source-documents.file', ['sourceDocument' => $sourceDocument, 'download' => 1]) }}" class="link-subtle">
                    Download the original
                </a>
            </p>
        @else
            <h2 class="section-heading mb-4">Body</h2>
            <div
                class="rounded-lg border p-5 text-sm leading-relaxed whitespace-pre-line max-h-96 overflow-y-auto"
                style="background: var(--color-surface-input); border-color: var(--color-surface-input-border); color: var(--color-text-primary);"
            >{{ $sourceDocument->body }}</div>
        @endif
    </div>

    <div class="flex items-center justify-end gap-3 pt-6 border-t" style="border-color: var(--color-divider);">
        {{-- Cancel deletes the unconfirmed document. Otherwise we'd
             accumulate orphaned drafts in the previous-submissions
             list every time the user backed out. --}}
        <form action="{{ route('source-documents.destroy', $sourceDocument) }}" method="POST" class="inline">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-secondary">
                Cancel
            </button>
        </form>

        <form action="{{ route('source-documents.extract', $sourceDocument) }}" method="POST" class="inline" data-extract-form>
            @csrf
            <button type="submit" class="btn-primary" data-submit-button>
                Confirm and extract
            </button>
        </form>
    </div>

    {{-- Loading overlay shown during the extraction step. The
         confirm-submit handler below makes it visible immediately;
         it stays visible until the browser navigates to the show
         page response. --}}
    <div class="loading-overlay" data-loading-overlay aria-hidden="true">
        <div class="loading-overlay-inner">
            <div class="loading-spinner" aria-hidden="true"></div>
            <p class="loading-message">Extracting records — this may take 10-15 seconds…</p>
        </div>
    </div>

    <script>
        (function () {
            const form = document.querySelector('[data-extract-form]');
            const overlay = document.querySelector('[data-loading-overlay]');
            if (!form || !overlay) return;

            form.addEventListener('submit', () => {
                overlay.classList.add('is-visible');
                overlay.removeAttribute('aria-hidden');
            });
        })();
    </script>
@endsection