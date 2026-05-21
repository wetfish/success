@extends('layouts.app')

@section('title', 'Tag review · ' . ($sourceDocument->title ?: 'Untitled document'))

@php
    // Build a flat list of all the document's tag review records so we
    // can compute the page-level counts. The grouped collection passed
    // from the controller is keyed by category and we walk it for the
    // visible rendering — but for counts we want the whole set in a
    // simple list.
    $allRecords = collect($grouped)->flatten(1);
    $totalCount = $allRecords->count();
    $pendingCount = $allRecords->where('status', 'pending')->count();
    $reviewedCount = $totalCount - $pendingCount;
    $progressPercent = $totalCount > 0 ? (int) round(($reviewedCount / $totalCount) * 100) : 0;

    // Map of category-key → category-label. Categories with no records
    // in $grouped are absent (the controller filters them out).
    $categoryLabels = \App\Http\Requests\TagRules::CATEGORY_LABELS;
@endphp

@section('content')
    @include('partials._resume-wizard-banner')
    <div class="mb-2">
        <a href="{{ route('source-documents.show', $sourceDocument) }}" class="link-subtle text-sm">
            ← {{ $sourceDocument->title ?: 'Untitled document' }}
        </a>
    </div>

    {{-- Progress header. Mirrors the entity-draft review page's pattern
         so the wizard steps feel visually consistent. The X-of-Y counter
         is what the JS increments on each decision; the progress bar's
         width matches via inline style updated on the fly. --}}
    <div class="mb-8">
        <div class="flex items-baseline justify-between mb-2">
            <h1 class="text-2xl font-semibold tracking-tight">
                Tag review
            </h1>
            <p class="text-sm" style="color: var(--color-text-muted);">
                <span data-tag-review-reviewed-count>{{ $reviewedCount }}</span>
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
            data-tag-review-progressbar
            data-total="{{ $totalCount }}"
        >
            <div
                class="h-full transition-all"
                style="width: {{ $progressPercent }}%; background: linear-gradient(90deg, rgb(217 70 163 / 0.2), var(--color-accent));"
                data-tag-review-progressbar-fill
            ></div>
        </div>
    </div>

    <p class="mb-6 text-sm" style="color: var(--color-text-muted);">
        Choose how each extracted tag should be handled. Accept creates a
        new catalog tag. Alias merges the extracted name into an existing
        tag. Reject discards the mention.
    </p>

    {{-- Page root. The JS auto-mounts on this element and reads the
         search URL from data-search-url. Each record card sits under
         a category heading, ordered by the controller's category list. --}}
    <div
        data-tag-review
        data-search-url="{{ route('tags.search') }}"
    >
        @foreach ($grouped as $categoryKey => $records)
            <section class="mb-8">
                <h2 class="metadata-label mb-3">
                    {{ $categoryKey === 'uncategorized' ? 'Uncategorized' : ($categoryLabels[$categoryKey] ?? ucfirst($categoryKey)) }}
                    <span style="color: var(--color-text-muted);" class="font-normal">
                        ({{ $records->count() }})
                    </span>
                </h2>

                <div class="space-y-3">
                    @foreach ($records as $record)
                        @include('tag-reviews._record', [
                            'sourceDocument' => $sourceDocument,
                            'record' => $record,
                            'mentions' => $mentions[strtolower($record->payload['extracted_name'] ?? '')] ?? [],
                        ])
                    @endforeach
                </div>
            </section>
        @endforeach

        {{-- Footer with the Next button. The href routes back through
             the review index endpoint, which then advances to the next
             wizard step (entity drafts today; people review when chunk
             4c lands). The button is disabled while pending records
             remain — JS flips it on the last decision. --}}
        <div class="mt-10 pt-6 flex justify-end" style="border-top: 1px solid var(--color-surface-input-border);">
            <a
                href="{{ route('source-documents.review.index', $sourceDocument) }}"
                class="btn-primary @if ($pendingCount > 0) is-disabled @endif"
                @if ($pendingCount > 0) aria-disabled="true" tabindex="-1" @endif
                data-tag-review-next
            >
                Next: Continue review →
            </a>
        </div>
    </div>
@endsection