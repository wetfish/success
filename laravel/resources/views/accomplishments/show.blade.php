@extends('layouts.app')

@php
    $confidenceLabels = \App\Http\Requests\AccomplishmentRules::CONFIDENCE_LABELS;
    $prominenceLabels = \App\Http\Requests\AccomplishmentRules::PROMINENCE_LABELS;

    if ($accomplishment->project_id) {
        $parentUrl = route('projects.show', $accomplishment->project);
        $parentLabel = $accomplishment->project->name;
        $contextLabel = 'In project ' . $accomplishment->project->name . ' at ' . $accomplishment->project->organization->name;
    } else {
        $parentUrl = route('positions.show', $accomplishment->position);
        $parentLabel = $accomplishment->position->title;
        $contextLabel = $accomplishment->position->title . ' at ' . $accomplishment->position->organization->name;
    }

    if ($accomplishment->isPointInTime()) {
        $dateDisplay = $accomplishment->date->format('M j, Y');
    } elseif ($accomplishment->isOngoing()) {
        $dateDisplay = $accomplishment->period_start->format('M Y') . ' — Present';
    } else {
        $dateDisplay = $accomplishment->period_start->format('M Y') . ' — ' . $accomplishment->period_end->format('M Y');
    }
@endphp

@section('title', 'Accomplishment — Success')

@section('content')
    <div class="mb-2">
        <a href="{{ $parentUrl }}" class="link-subtle text-sm">
            ← {{ $parentLabel }}
        </a>
    </div>

    <div class="flex items-start justify-between mb-8 gap-4">
        <div class="min-w-0">
            <p class="text-sm" style="color: var(--color-text-secondary);">{{ $contextLabel }}</p>
            <h1 class="text-2xl font-medium tracking-tight mt-2 leading-snug">{{ $accomplishment->description }}</h1>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('accomplishments.edit', $accomplishment) }}" class="btn-secondary">
                Edit
            </a>
            <form
                method="POST"
                action="{{ route('accomplishments.destroy', $accomplishment) }}"
                onsubmit="return confirm('Delete this accomplishment? This action soft-deletes the record — it can be recovered from the database.')"
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
            <dt class="metadata-label">When</dt>
            <dd class="mt-1 text-sm">{{ $dateDisplay }}</dd>
        </div>

        <div>
            <dt class="metadata-label">Confidence</dt>
            <dd class="mt-1 text-sm slider-label-{{ $accomplishment->confidence }}">
                {{ $confidenceLabels[$accomplishment->confidence] ?? '' }}
            </dd>
        </div>

        <div>
            <dt class="metadata-label">Prominence</dt>
            <dd class="mt-1 text-sm slider-label-{{ $accomplishment->prominence }}">
                {{ $prominenceLabels[$accomplishment->prominence] ?? '' }}
            </dd>
        </div>

        @if ($accomplishment->impact_metric || $accomplishment->impact_value || $accomplishment->impact_unit)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Impact</dt>
                <dd class="mt-1 text-sm">
                    @if ($accomplishment->impact_value)
                        <span class="font-medium">{{ $accomplishment->impact_value }}</span>
                    @endif
                    @if ($accomplishment->impact_unit)
                        {{ $accomplishment->impact_unit }}
                    @endif
                    @if ($accomplishment->impact_metric)
                        <span style="color: var(--color-text-secondary);"> · {{ $accomplishment->impact_metric }}</span>
                    @endif
                </dd>
            </div>
        @endif

        @if ($accomplishment->context_notes)
            <div class="sm:col-span-3">
                <dt class="metadata-label">Context</dt>
                <dd class="mt-1 text-sm whitespace-pre-line leading-relaxed" style="color: var(--color-text-secondary);">{{ $accomplishment->context_notes }}</dd>
            </div>
        @endif
    </dl>
@endsection