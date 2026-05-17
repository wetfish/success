@extends('layouts.app')

@section('title', $jobListing->role_title . ' — Success')

@section('content')
    <div class="mb-2">
        <a href="{{ route('job-listings.index') }}" class="link-subtle text-sm">
            ← Job Listings
        </a>
    </div>

    <div class="flex items-start justify-between mb-8 gap-4">
        <div class="min-w-0">
            <h1 class="text-3xl font-semibold tracking-tight">{{ $jobListing->role_title }}</h1>
            <p class="mt-2" style="color: var(--color-text-secondary);">
                <a href="{{ route('organizations.show', $jobListing->organization) }}" class="link-emphasis">
                    {{ $jobListing->organization->name }}
                </a>
                @if ($jobListing->location)
                    · {{ $jobListing->location }}
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('job-listings.edit', $jobListing) }}" class="btn-secondary">
                Edit
            </a>
            <form
                method="POST"
                action="{{ route('job-listings.destroy', $jobListing) }}"
                onsubmit="return confirm('Delete this listing? This action soft-deletes the record — it can be recovered from the database.')"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-destructive">
                    Delete
                </button>
            </form>
        </div>
    </div>

    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-5 mb-12 pb-12 border-b" style="border-color: var(--color-divider);">
        <div>
            <dt class="metadata-label">Status</dt>
            <dd class="mt-1 text-sm capitalize">{{ $jobListing->status }}</dd>
        </div>

        @if ($jobListing->compensation_range)
            <div>
                <dt class="metadata-label">Compensation</dt>
                <dd class="mt-1 text-sm">{{ $jobListing->compensation_range }}</dd>
            </div>
        @endif

        @if ($jobListing->date_posted)
            <div>
                <dt class="metadata-label">Date posted</dt>
                <dd class="mt-1 text-sm">{{ $jobListing->date_posted->format('M j, Y') }}</dd>
            </div>
        @endif

        @if ($jobListing->source_url)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Source</dt>
                <dd class="mt-1 text-sm">
                    <a href="{{ $jobListing->source_url }}" target="_blank" rel="noopener" class="link-emphasis">
                        {{ $jobListing->source_url }}
                    </a>
                </dd>
            </div>
        @endif
    </dl>

    <div class="mb-12">
        <h2 class="text-lg font-semibold mb-4">Listing text</h2>
        <div
            class="rounded-lg border p-6 text-sm whitespace-pre-line leading-relaxed"
            style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
        >{{ $jobListing->body }}</div>
    </div>
@endsection