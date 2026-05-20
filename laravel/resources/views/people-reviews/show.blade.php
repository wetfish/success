@extends('layouts.app')

@section('title', 'People review · ' . ($sourceDocument->title ?: 'Untitled document'))

@php
    $totalCount = $records->count();
    $pendingCount = $records->where('status', 'pending')->count();
    $reviewedCount = $totalCount - $pendingCount;
    $progressPercent = $totalCount > 0 ? (int) round(($reviewedCount / $totalCount) * 100) : 0;
@endphp

@section('content')
    @include('partials._resume-wizard-banner')
    <div class="mb-2">
        <a href="{{ route('source-documents.show', $sourceDocument) }}" class="link-subtle text-sm">
            ← {{ $sourceDocument->title ?: 'Untitled document' }}
        </a>
    </div>

    <div class="mb-8">
        <div class="flex items-baseline justify-between mb-2">
            <h1 class="text-2xl font-semibold tracking-tight">
                People review
            </h1>
            <p class="text-sm" style="color: var(--color-text-muted);">
                <span data-people-review-reviewed-count>{{ $reviewedCount }}</span>
                of {{ $totalCount }} reviewed
            </p>
        </div>
        <div
            class="h-2 rounded-full overflow-hidden"
            style="background: var(--color-surface-input-border);"
            role="progressbar"
            aria-valuenow="{{ $progressPercent }}"
            aria-valuemin="0"
            aria-valuemax="100"
            data-people-review-progressbar
            data-total="{{ $totalCount }}"
        >
            <div
                class="h-full transition-all"
                style="width: {{ $progressPercent }}%; background: linear-gradient(90deg, rgb(217 70 163 / 0.2), var(--color-accent));"
                data-people-review-progressbar-fill
            ></div>
        </div>
    </div>

    <p class="mb-6 text-sm" style="color: var(--color-text-muted);">
        Choose how each extracted person should be handled. Accept creates
        a new catalog person (or links to an existing match). Reject
        discards the mention.
    </p>

    {{-- Page root. The JS auto-mounts on this element. No search URL
         needed — person review has no alias picker. --}}
    <div data-people-review>
        <div class="space-y-3">
            @foreach ($records as $record)
                @include('people-reviews._record', [
                    'sourceDocument' => $sourceDocument,
                    'record' => $record,
                    'mentions' => $mentions[strtolower($record->payload['extracted_name'] ?? '')] ?? [],
                ])
            @endforeach
        </div>

        <div class="mt-10 pt-6 flex justify-end" style="border-top: 1px solid var(--color-surface-input-border);">
            <a
                href="{{ route('source-documents.review.index', $sourceDocument) }}"
                class="btn-primary @if ($pendingCount > 0) is-disabled @endif"
                @if ($pendingCount > 0) aria-disabled="true" tabindex="-1" @endif
                data-people-review-next
            >
                Next: Continue review →
            </a>
        </div>
    </div>
@endsection