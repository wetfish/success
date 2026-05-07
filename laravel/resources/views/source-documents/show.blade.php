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

    <div>
        <h2 class="section-heading mb-4">Body</h2>

        @if ($sourceDocument->body)
            {{-- whitespace-pre-line preserves line breaks the user typed
                 while still allowing wrapping on long lines. The faint
                 surface mirrors how the body looked in the input
                 textarea, reinforcing "this is what you submitted." --}}
            <div
                class="rounded-lg border p-5 text-sm leading-relaxed whitespace-pre-line"
                style="background: var(--color-surface-input); border-color: var(--color-surface-input-border); color: var(--color-text-primary);"
            >{{ $sourceDocument->body }}</div>
        @else
            <p class="text-sm" style="color: var(--color-text-muted);">
                No body content. (PDF source documents store their content as a file rather than text.)
            </p>
        @endif
    </div>
@endsection