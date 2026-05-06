@extends('layouts.app')

@php
    $confidenceLabels = \App\Http\Requests\AccomplishmentRules::CONFIDENCE_LABELS;
    $prominenceLabels = \App\Http\Requests\AccomplishmentRules::PROMINENCE_LABELS;

    if ($accomplishment->project_id) {
        $parentUrl = route('projects.show', $accomplishment->project);
        $parentLabel = $accomplishment->project->name;
    } else {
        $parentUrl = route('positions.show', $accomplishment->position);
        $parentLabel = $accomplishment->position->title;
    }

    if ($accomplishment->isPointInTime()) {
        $dateDisplay = $accomplishment->date->format('M j, Y');
    } elseif ($accomplishment->isOngoing()) {
        $dateDisplay = $accomplishment->period_start->format('M Y') . ' — Present';
    } else {
        $dateDisplay = $accomplishment->period_start->format('M Y') . ' — ' . $accomplishment->period_end->format('M Y');
    }
@endphp

@section('title', $accomplishment->title . ' — Success')

@section('content')
    <div class="mb-2">
        <a href="{{ $parentUrl }}" class="link-subtle text-sm">
            ← {{ $parentLabel }}
        </a>
    </div>

    <div class="flex items-start justify-between mb-6 gap-4">
        <div class="min-w-0">
            <h1 class="text-3xl font-semibold tracking-tight">{{ $accomplishment->title }}</h1>
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

    {{-- At-a-glance metadata sits directly under the title so it's
         scannable before reading the description prose. --}}
    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-5 mb-8">
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
    </dl>

    {{-- The substantive description — this is what becomes a resume bullet. --}}
    <p class="text-base leading-relaxed mb-12 pb-12 border-b whitespace-pre-line" style="border-color: var(--color-divider);">{{ $accomplishment->description }}</p>

    {{-- Impact and context appear below the divider when present. --}}
    @if ($accomplishment->impact_metric || $accomplishment->impact_value || $accomplishment->impact_unit || $accomplishment->context_notes)
        <dl class="grid grid-cols-1 gap-y-5">
            @if ($accomplishment->impact_metric || $accomplishment->impact_value || $accomplishment->impact_unit)
                <div>
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
                <div>
                    <dt class="metadata-label">Context</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line leading-relaxed" style="color: var(--color-text-secondary);">{{ $accomplishment->context_notes }}</dd>
                </div>
            @endif
        </dl>
    @endif
@endsection