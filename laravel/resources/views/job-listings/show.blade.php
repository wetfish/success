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

    {{-- Resume generation section --}}
    <div class="mb-12">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold">Resume Drafts</h2>
            <form method="POST" action="{{ route('resume-drafts.create', $jobListing) }}">
                @csrf
                <button type="submit" class="btn-primary">
                    Generate Resume
                </button>
            </form>
        </div>

        @if ($jobListing->resumeDrafts->isEmpty())
            <div
                class="border border-dashed rounded-lg p-8 text-center text-sm"
                style="border-color: var(--color-surface-input-border); color: var(--color-text-secondary);"
            >
                No resume drafts yet. Click "Generate Resume" to analyze this listing against your catalog and start building a tailored resume.
            </div>
        @else
            <ul
                class="rounded-lg overflow-hidden border"
                style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
            >
                @foreach ($jobListing->resumeDrafts->sortByDesc('created_at') as $draft)
                    <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                        <a href="{{ route('resume-drafts.show', $draft) }}" class="list-row">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <h3 class="font-medium">
                                        Draft #{{ $draft->id }}
                                    </h3>
                                    <p class="text-sm mt-0.5" style="color: var(--color-text-secondary);">
                                        Created {{ $draft->created_at->format('M j, Y g:ia') }}
                                        · {{ $draft->selections()->where('selected', true)->count() }} items selected
                                    </p>
                                </div>
                                <span
                                    class="text-xs font-medium px-2 py-0.5 rounded-full capitalize shrink-0"
                                    style="background: var(--color-surface-input-border); color: var(--color-text-secondary);"
                                >
                                    {{ $draft->status }}
                                </span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection