@extends('layouts.app')

@section('title', ($sourceDocument->title ?: 'Untitled document') . ' — Success')

@section('content')
    <div class="mb-2">
        <a href="{{ route('career-input.index') }}" class="link-subtle text-sm">
            ← Career Input
        </a>
    </div>

    <div class="mb-8">
        <h1 class="text-3xl font-semibold tracking-tight">
            {{ $sourceDocument->title ?: 'Untitled document' }}
        </h1>
    </div>

    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-5 mb-10 pb-10 border-b" style="border-color: var(--color-divider);">
        <div>
            <dt class="metadata-label">Kind</dt>
            <dd class="mt-1 text-sm capitalize">{{ str_replace('_', ' ', $sourceDocument->kind) }}</dd>
        </div>

        @if ($sourceDocument->file_type)
            <div>
                <dt class="metadata-label">Source</dt>
                <dd class="mt-1 text-sm uppercase tracking-wide">{{ $sourceDocument->file_type }}</dd>
            </div>
        @endif

        <div>
            <dt class="metadata-label">Submitted</dt>
            <dd class="mt-1 text-sm">{{ $sourceDocument->created_at->format('M j, Y') }}</dd>
        </div>

        @if ($sourceDocument->context_date)
            <div>
                <dt class="metadata-label">Context date</dt>
                <dd class="mt-1 text-sm">{{ $sourceDocument->context_date->format('M j, Y') }}</dd>
            </div>
        @endif

        @if ($sourceDocument->context_notes)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Context</dt>
                <dd class="mt-1 text-sm whitespace-pre-line leading-relaxed" style="color: var(--color-text-secondary);">{{ $sourceDocument->context_notes }}</dd>
            </div>
        @endif
    </dl>

    {{-- Drafts summary section. Shown only when extraction has run
         (total > 0). Surfaces the review entry point — the actual
         queue page is built in the next mini-slice. --}}
    @if ($draftCounts['total'] > 0)
        <div
            class="rounded-lg border p-5 mb-10 flex items-center justify-between gap-4"
            style="background: var(--color-surface-input); border-color: var(--color-surface-input-border);"
        >
            <div>
                <h2 class="font-semibold text-base mb-1">
                    {{ $draftCounts['total'] }}
                    {{ $draftCounts['total'] === 1 ? 'draft' : 'drafts' }}
                    extracted
                </h2>
                <p class="text-sm" style="color: var(--color-text-secondary);">
                    @if ($draftCounts['pending'] > 0)
                        {{ $draftCounts['pending'] }} pending review
                        @if ($draftCounts['confirmed'] + $draftCounts['rejected'] + $draftCounts['merged'] > 0)
                            · {{ $draftCounts['confirmed'] }} confirmed,
                            {{ $draftCounts['rejected'] }} rejected,
                            {{ $draftCounts['merged'] }} merged
                        @endif
                    @else
                        All reviewed —
                        {{ $draftCounts['confirmed'] }} confirmed,
                        {{ $draftCounts['rejected'] }} rejected,
                        {{ $draftCounts['merged'] }} merged
                    @endif
                </p>
            </div>
            {{-- Review button is always visible once drafts exist.
                 Pink primary style when there's pending work to do;
                 secondary muted style when everything has been
                 reviewed (the user can still browse). --}}
            <a
                href="{{ route('source-documents.review.index', $sourceDocument) }}"
                class="{{ $draftCounts['pending'] > 0 ? 'btn-primary' : 'btn-secondary' }} shrink-0"
            >
                Review drafts
            </a>
        </div>
    @endif

    <div>
        @if ($sourceDocument->isPdf())
            {{-- PDF documents are embedded inline so the user can
                 view the original. The download link is a fallback
                 for browsers that can't render PDFs inline. --}}
            <h2 class="section-heading mb-4">PDF</h2>
            <iframe
                src="{{ route('source-documents.file', $sourceDocument) }}"
                class="w-full rounded-lg border"
                style="height: 600px; background: var(--color-surface-input); border-color: var(--color-surface-input-border);"
                title="{{ $sourceDocument->title ?: 'PDF source document' }}"
            ></iframe>
            <p class="mt-2 text-xs" style="color: var(--color-text-muted);">
                Can't see the PDF?
                <a href="{{ route('source-documents.file', ['sourceDocument' => $sourceDocument, 'download' => 1]) }}" class="link-subtle">
                    Download the original
                </a>
            </p>
        @elseif ($sourceDocument->body)
            <h2 class="section-heading mb-4">Body</h2>
            {{-- whitespace-pre-line preserves line breaks the user typed
                 while still allowing wrapping on long lines. The faint
                 surface mirrors how the body looked in the input
                 textarea, reinforcing "this is what you submitted." --}}
            <div
                class="rounded-lg border p-5 text-sm leading-relaxed whitespace-pre-line"
                style="background: var(--color-surface-input); border-color: var(--color-surface-input-border); color: var(--color-text-primary);"
            >{{ $sourceDocument->body }}</div>
        @else
            <h2 class="section-heading mb-4">Body</h2>
            <p class="text-sm" style="color: var(--color-text-muted);">
                No body content.
            </p>
        @endif
    </div>
@endsection