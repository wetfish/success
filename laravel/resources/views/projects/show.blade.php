@extends('layouts.app')

@section('title', $project->name . ' — Success')

@php
    $formatProjectDate = function ($date, $precision) {
        if (! $date) {
            return null;
        }
        return match ($precision) {
            'day' => $date->format('M j, Y'),
            'month' => $date->format('M Y'),
            'quarter' => 'Q' . ceil($date->format('n') / 3) . ' ' . $date->format('Y'),
            'year' => $date->format('Y'),
            default => $date->format('M Y'),
        };
    };

    $startDisplay = $formatProjectDate($project->start_date, $project->date_precision);
    $endDisplay = $formatProjectDate($project->end_date, $project->date_precision);

    $accomplishments = $project->accomplishments()
        ->orderByRaw('(period_start IS NOT NULL AND period_end IS NULL) DESC')
        ->orderByRaw('COALESCE(period_end, date) DESC')
        ->get();
@endphp

@section('content')
    <div class="mb-2">
        @if ($project->parentProject)
            <a href="{{ route('projects.show', $project->parentProject) }}" class="link-subtle text-sm">
                ← {{ $project->parentProject->name }}
            </a>
        @elseif ($project->position)
            <a href="{{ route('positions.show', $project->position) }}" class="link-subtle text-sm">
                ← {{ $project->position->title }}
            </a>
        @else
            <a href="{{ route('organizations.show', $project->organization) }}" class="link-subtle text-sm">
                ← {{ $project->organization->name }}
            </a>
        @endif
    </div>

    <div class="flex items-start justify-between mb-8 gap-4">
        <div class="min-w-0">
            <h1 class="text-3xl font-semibold tracking-tight">{{ $project->name }}</h1>
            @if ($project->public_name)
                <p class="mt-2" style="color: var(--color-text-secondary);">
                    Publicly known as {{ $project->public_name }}
                </p>
            @endif
            @if ($project->description)
                <p class="mt-2" style="color: var(--color-text-secondary);">{{ $project->description }}</p>
            @endif
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('projects.edit', $project) }}" class="btn-secondary">
                Edit
            </a>
            <form
                method="POST"
                action="{{ route('projects.destroy', $project) }}"
                onsubmit="return confirm('Delete the {{ addslashes($project->name) }} project? This action soft-deletes the record — it can be recovered from the database.')"
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
            <dt class="metadata-label">Organization</dt>
            <dd class="mt-1 text-sm">
                <a href="{{ route('organizations.show', $project->organization) }}" class="link-emphasis">
                    {{ $project->organization->name }}
                </a>
            </dd>
        </div>

        @if ($project->position)
            <div>
                <dt class="metadata-label">Position</dt>
                <dd class="mt-1 text-sm">
                    <a href="{{ route('positions.show', $project->position) }}" class="link-emphasis">
                        {{ $project->position->title }}
                    </a>
                </dd>
            </div>
        @endif

        <div>
            <dt class="metadata-label">Visibility</dt>
            <dd class="mt-1 text-sm capitalize">{{ str_replace('_', ' ', $project->visibility) }}</dd>
        </div>

        <div>
            <dt class="metadata-label">Your role</dt>
            <dd class="mt-1 text-sm capitalize">{{ $project->contribution_level }}</dd>
        </div>

        @if ($project->status)
            <div>
                <dt class="metadata-label">Status</dt>
                <dd class="mt-1 text-sm capitalize">{{ $project->status }}</dd>
            </div>
        @endif

        @if ($project->team_size)
            <div>
                <dt class="metadata-label">Team size</dt>
                <dd class="mt-1 text-sm">{{ $project->team_size }}</dd>
            </div>
        @endif

        @if ($startDisplay)
            <div>
                <dt class="metadata-label">Timeline</dt>
                <dd class="mt-1 text-sm">
                    {{ $startDisplay }} — {{ $endDisplay ?: 'Present' }}
                </dd>
            </div>
        @endif

        @if ($project->contribution_type)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Contribution type</dt>
                <dd class="mt-1 text-sm">{{ $project->contribution_type }}</dd>
            </div>
        @endif
    </dl>

    @if ($project->problem || $project->constraints || $project->approach || $project->outcome || $project->rationale)
        <div class="space-y-8 mb-12 pb-12 border-b" style="border-color: var(--color-divider);">
            @if ($project->problem)
                <div>
                    <h2 class="metadata-label mb-2">Problem</h2>
                    <p class="text-sm whitespace-pre-line leading-relaxed">{{ $project->problem }}</p>
                </div>
            @endif

            @if ($project->constraints)
                <div>
                    <h2 class="metadata-label mb-2">Constraints</h2>
                    <p class="text-sm whitespace-pre-line leading-relaxed">{{ $project->constraints }}</p>
                </div>
            @endif

            @if ($project->approach)
                <div>
                    <h2 class="metadata-label mb-2">Approach</h2>
                    <p class="text-sm whitespace-pre-line leading-relaxed">{{ $project->approach }}</p>
                </div>
            @endif

            @if ($project->outcome)
                <div>
                    <h2 class="metadata-label mb-2">Outcome</h2>
                    <p class="text-sm whitespace-pre-line leading-relaxed">{{ $project->outcome }}</p>
                </div>
            @endif

            @if ($project->rationale)
                <div>
                    <h2 class="metadata-label mb-2">Rationale</h2>
                    <p class="text-sm whitespace-pre-line leading-relaxed">{{ $project->rationale }}</p>
                </div>
            @endif
        </div>
    @endif

    @if ($project->user_notes)
        <div class="mb-12 pb-12 border-b" style="border-color: var(--color-divider);">
            <h2 class="metadata-label mb-2">Private notes</h2>
            <p class="text-sm whitespace-pre-line leading-relaxed" style="color: var(--color-text-secondary);">{{ $project->user_notes }}</p>
        </div>
    @endif

    <div class="mb-12">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold">Sub-projects</h2>
            <a href="{{ route('projects.createSubProject', $project) }}" class="btn-primary">
                Add sub-project
            </a>
        </div>

        @if ($childProjects->isEmpty())
            <div
                class="border border-dashed rounded-lg p-8 text-center text-sm"
                style="border-color: var(--color-surface-input-border); color: var(--color-text-secondary);"
            >
                No sub-projects yet. Use sub-projects to break a long-running workstream into discrete pieces.
            </div>
        @else
            <ul
                class="rounded-lg overflow-hidden border"
                style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
            >
                @foreach ($childProjects as $child)
                    <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                        <a href="{{ route('projects.show', $child) }}" class="list-row">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <h3 class="font-medium truncate">{{ $child->name }}</h3>
                                    @if ($child->description)
                                        <p class="text-sm truncate mt-0.5" style="color: var(--color-text-secondary);">{{ $child->description }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 text-xs shrink-0" style="color: var(--color-text-muted);">
                                    <span class="capitalize">{{ str_replace('_', ' ', $child->visibility) }}</span>
                                </div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div>
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold">Accomplishments</h2>
            <a href="{{ route('accomplishments.createForProject', $project) }}" class="btn-primary">
                Add accomplishment
            </a>
        </div>

        @if ($accomplishments->isEmpty())
            <div
                class="border border-dashed rounded-lg p-8 text-center text-sm"
                style="border-color: var(--color-surface-input-border); color: var(--color-text-secondary);"
            >
                No accomplishments yet. Discrete, measurable wins from this project will appear here.
            </div>
        @else
            <ul
                class="rounded-lg overflow-hidden border"
                style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
            >
                @foreach ($accomplishments as $accomplishment)
                    <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                        <a href="{{ route('accomplishments.show', $accomplishment) }}" class="list-row">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <h3 class="font-medium truncate">{{ $accomplishment->title }}</h3>
                                    @if ($accomplishment->impact_value)
                                        <p class="text-xs mt-0.5" style="color: var(--color-text-secondary);">
                                            {{ $accomplishment->impact_value }}
                                            @if ($accomplishment->impact_unit) {{ $accomplishment->impact_unit }} @endif
                                            @if ($accomplishment->impact_metric) · {{ $accomplishment->impact_metric }} @endif
                                        </p>
                                    @endif
                                </div>
                                <div class="text-xs shrink-0" style="color: var(--color-text-muted);">
                                    @if ($accomplishment->isOngoing())
                                        Ongoing
                                    @elseif ($accomplishment->isPointInTime())
                                        {{ $accomplishment->date->format('M Y') }}
                                    @else
                                        {{ $accomplishment->period_end->format('M Y') }}
                                    @endif
                                </div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection