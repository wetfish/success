@extends('layouts.app')

@section('title', $organization->name . ' — Success')

@section('content')
    <div class="mb-2">
        <a href="{{ route('organizations.index') }}" class="link-subtle text-sm">
            ← Organizations
        </a>
    </div>

    <div class="flex items-start justify-between mb-8 gap-4">
        <div class="min-w-0">
            <h1 class="text-3xl font-semibold tracking-tight">{{ $organization->name }}</h1>
            @if ($organization->tagline)
                <p class="mt-2" style="color: var(--color-text-secondary);">{{ $organization->tagline }}</p>
            @endif
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('organizations.edit', $organization) }}" class="btn-secondary">
                Edit
            </a>
            <form
                method="POST"
                action="{{ route('organizations.destroy', $organization) }}"
                onsubmit="return confirm('Delete {{ addslashes($organization->name) }}? This action soft-deletes the record — it can be recovered from the database.')"
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
            <dt class="metadata-label">Type</dt>
            <dd class="mt-1 text-sm capitalize">{{ str_replace('_', ' ', $organization->type) }}</dd>
        </div>

        @if ($organization->status)
            <div>
                <dt class="metadata-label">Status</dt>
                <dd class="mt-1 text-sm capitalize">{{ $organization->status }}</dd>
            </div>
        @endif

        @if ($organization->headquarters)
            <div>
                <dt class="metadata-label">Headquarters</dt>
                <dd class="mt-1 text-sm">{{ $organization->headquarters }}</dd>
            </div>
        @endif

        @if ($organization->founded_year)
            <div>
                <dt class="metadata-label">Founded</dt>
                <dd class="mt-1 text-sm">{{ $organization->founded_year }}</dd>
            </div>
        @endif

        @if ($organization->size_estimate)
            <div>
                <dt class="metadata-label">Size</dt>
                <dd class="mt-1 text-sm">{{ $organization->size_estimate }}</dd>
            </div>
        @endif

        @if ($organization->website)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Website</dt>
                <dd class="mt-1 text-sm">
                    <a href="{{ $organization->website }}" target="_blank" rel="noopener" class="link-emphasis">
                        {{ $organization->website }}
                    </a>
                </dd>
            </div>
        @endif

        @if ($organization->description)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Description</dt>
                <dd class="mt-1 text-sm whitespace-pre-line leading-relaxed">{{ $organization->description }}</dd>
            </div>
        @endif

        @if ($organization->user_notes)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Private notes</dt>
                <dd class="mt-1 text-sm whitespace-pre-line leading-relaxed" style="color: var(--color-text-secondary);">{{ $organization->user_notes }}</dd>
            </div>
        @endif
    </dl>

    <div>
        <h2 class="text-lg font-semibold mb-3">Positions</h2>
        <div
            class="border border-dashed rounded-lg p-8 text-center text-sm"
            style="border-color: var(--color-surface-input-border); color: var(--color-text-secondary);"
        >
            Positions UI is coming in the next slice of development.
        </div>
    </div>
@endsection