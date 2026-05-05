@extends('layouts.app')

@section('title', $position->title . ' — Success')

@section('content')
    <div class="mb-2">
        <a href="{{ route('organizations.show', $position->organization) }}" class="link-subtle text-sm">
            ← {{ $position->organization->name }}
        </a>
    </div>

    <div class="flex items-start justify-between mb-8 gap-4">
        <div class="min-w-0">
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-3xl font-semibold tracking-tight">{{ $position->title }}</h1>
                @if ($position->isCurrent())
                    <span
                        class="text-xs font-medium px-2 py-0.5 rounded-full"
                        style="background: var(--color-accent); color: var(--color-accent-text);"
                    >
                        Current
                    </span>
                @endif
            </div>
            <p class="mt-2" style="color: var(--color-text-secondary);">
                {{ $position->organization->name }}
            </p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('positions.edit', $position) }}" class="btn-secondary">
                Edit
            </a>
            <form
                method="POST"
                action="{{ route('positions.destroy', $position) }}"
                onsubmit="return confirm('Delete the {{ addslashes($position->title) }} position? This action soft-deletes the record — it can be recovered from the database.')"
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
            <dt class="metadata-label">Employment type</dt>
            <dd class="mt-1 text-sm capitalize">{{ str_replace('_', ' ', $position->employment_type) }}</dd>
        </div>

        <div>
            <dt class="metadata-label">Location</dt>
            <dd class="mt-1 text-sm capitalize">
                {{ str_replace('_', ' ', $position->location_arrangement) }}
                @if ($position->location_text)
                    <span style="color: var(--color-text-secondary);"> · {{ $position->location_text }}</span>
                @endif
            </dd>
        </div>

        <div>
            <dt class="metadata-label">Dates</dt>
            <dd class="mt-1 text-sm">
                {{ $position->start_date->format('M Y') }} —
                {{ $position->end_date ? $position->end_date->format('M Y') : 'Present' }}
            </dd>
        </div>

        @if ($position->team_name || $position->team_size_immediate || $position->team_size_extended)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Team</dt>
                <dd class="mt-1 text-sm">
                    @if ($position->team_name)
                        {{ $position->team_name }}
                    @endif
                    @if ($position->team_size_immediate || $position->team_size_extended)
                        <span style="color: var(--color-text-secondary);">
                            @if ($position->team_name) · @endif
                            @if ($position->team_size_immediate)
                                {{ $position->team_size_immediate }} immediate
                            @endif
                            @if ($position->team_size_immediate && $position->team_size_extended) / @endif
                            @if ($position->team_size_extended)
                                {{ $position->team_size_extended }} extended
                            @endif
                        </span>
                    @endif
                </dd>
            </div>
        @endif

        @if ($position->mandate)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Mandate</dt>
                <dd class="mt-1 text-sm whitespace-pre-line leading-relaxed">{{ $position->mandate }}</dd>
            </div>
        @endif

        @if ($position->reason_for_leaving && $position->reason_for_leaving !== 'still_employed')
            <div class="sm:col-span-3">
                <dt class="metadata-label">Reason for leaving</dt>
                <dd class="mt-1 text-sm capitalize">{{ str_replace('_', ' ', $position->reason_for_leaving) }}</dd>
                @if ($position->reason_for_leaving_notes)
                    <dd class="mt-2 text-sm whitespace-pre-line leading-relaxed" style="color: var(--color-text-secondary);">
                        {{ $position->reason_for_leaving_notes }}
                    </dd>
                @endif
            </div>
        @endif

        @if ($position->user_notes)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Private notes</dt>
                <dd class="mt-1 text-sm whitespace-pre-line leading-relaxed" style="color: var(--color-text-secondary);">{{ $position->user_notes }}</dd>
            </div>
        @endif
    </dl>

    <div>
        <h2 class="text-lg font-semibold mb-3">Projects</h2>
        <div
            class="border border-dashed rounded-lg p-8 text-center text-sm"
            style="border-color: var(--color-surface-input-border); color: var(--color-text-secondary);"
        >
            Projects UI is coming in the next slice of development.
        </div>
    </div>
@endsection