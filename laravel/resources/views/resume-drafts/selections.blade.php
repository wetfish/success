@extends('layouts.app')

@section('title', 'Select resume content · ' . $jobListing->role_title)

@php
    $totalCount = $draft->selections->count();
    $selectedCount = $draft->selections->where('selected', true)->count();
@endphp

@section('content')
    <div class="mb-2">
        <a href="{{ route('job-listings.show', $jobListing) }}" class="link-subtle text-sm">
            ← {{ $jobListing->role_title }} at {{ $jobListing->organization->name }}
        </a>
    </div>

    <div class="mb-8">
        <div class="flex items-baseline justify-between mb-2">
            <h1 class="text-2xl font-semibold tracking-tight">
                Select resume content
            </h1>
            <p class="text-sm" style="color: var(--color-text-muted);">
                <span data-selection-count>{{ $selectedCount }}</span>
                of {{ $totalCount }} selected
            </p>
        </div>
        <div
            class="h-2 rounded-full overflow-hidden"
            style="background: var(--color-surface-input-border);"
        >
            <div
                class="h-full transition-all"
                style="width: {{ $totalCount > 0 ? round(($selectedCount / $totalCount) * 100) : 0 }}%; background: linear-gradient(90deg, rgb(217 70 163 / 0.2), var(--color-accent));"
                data-selection-bar
                data-total="{{ $totalCount }}"
            ></div>
        </div>
    </div>

    <p class="mb-6 text-sm" style="color: var(--color-text-muted);">
        The AI analyzed your catalog against this listing and suggested
        items to include. Toggle items on or off, then confirm to generate
        your tailored resume draft.
    </p>

    <div data-selection-review>
        {{-- Positions section with nested projects/accomplishments --}}
        @if (isset($grouped['Position']))
            <section class="mb-8">
                <h2 class="metadata-label mb-3">
                    {{ $grouped['Position']['label'] }}
                    <span style="color: var(--color-text-muted);" class="font-normal">
                        ({{ $grouped['Position']['selections']->count() }})
                    </span>
                </h2>

                <div class="space-y-3">
                    @foreach ($grouped['Position']['selections'] as $selection)
                        @php $position = $selection->selectable; @endphp
                        @if ($position)
                            @include('resume-drafts._selection-card', [
                                'draft' => $draft,
                                'selection' => $selection,
                                'title' => $position->title . ' at ' . ($position->organization?->name ?? 'Unknown'),
                                'subtitle' => implode(' · ', array_filter([
                                    $position->start_date?->format('M Y'),
                                    $position->end_date ? $position->end_date->format('M Y') : 'Present',
                                    $position->employment_type ? str_replace('_', ' ', $position->employment_type) : null,
                                ])),
                                'indent' => 0,
                            ])

                            {{-- Nested projects under this position --}}
                            @foreach ($projectsByPosition[$position->id] ?? [] as $projectSel)
                                @php $project = $projectSel->selectable; @endphp
                                @if ($project)
                                    @include('resume-drafts._selection-card', [
                                        'draft' => $draft,
                                        'selection' => $projectSel,
                                        'title' => $project->name,
                                        'subtitle' => $project->description ? \Illuminate\Support\Str::limit($project->description, 120) : null,
                                        'indent' => 1,
                                    ])

                                    {{-- Accomplishments under this project --}}
                                    @foreach ($accomplishmentsByProject[$project->id] ?? [] as $accSel)
                                        @php $acc = $accSel->selectable; @endphp
                                        @if ($acc)
                                            @include('resume-drafts._selection-card', [
                                                'draft' => $draft,
                                                'selection' => $accSel,
                                                'title' => $acc->title,
                                                'subtitle' => $acc->impact_metric
                                                    ? implode(' ', array_filter([$acc->impact_metric, $acc->impact_value, $acc->impact_unit]))
                                                    : null,
                                                'indent' => 2,
                                            ])
                                        @endif
                                    @endforeach
                                @endif
                            @endforeach

                            {{-- Direct accomplishments under position (no project) --}}
                            @foreach ($accomplishmentsByPosition[$position->id] ?? [] as $accSel)
                                @php $acc = $accSel->selectable; @endphp
                                @if ($acc)
                                    @include('resume-drafts._selection-card', [
                                        'draft' => $draft,
                                        'selection' => $accSel,
                                        'title' => $acc->title,
                                        'subtitle' => $acc->impact_metric
                                            ? implode(' ', array_filter([$acc->impact_metric, $acc->impact_value, $acc->impact_unit]))
                                            : null,
                                        'indent' => 1,
                                    ])
                                @endif
                            @endforeach
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Standalone sections: career themes, tags, links
             (projects and accomplishments rendered above under positions) --}}
        @foreach (['CareerTheme', 'Tag', 'Link'] as $type)
            @if (isset($grouped[$type]))
                <section class="mb-8">
                    <h2 class="metadata-label mb-3">
                        {{ $grouped[$type]['label'] }}
                        <span style="color: var(--color-text-muted);" class="font-normal">
                            ({{ $grouped[$type]['selections']->count() }})
                        </span>
                    </h2>

                    <div class="space-y-3">
                        @foreach ($grouped[$type]['selections'] as $selection)
                            @php $item = $selection->selectable; @endphp
                            @if ($item)
                                @include('resume-drafts._selection-card', [
                                    'draft' => $draft,
                                    'selection' => $selection,
                                    'title' => $item->name ?? $item->title ?? $item->url ?? '(untitled)',
                                    'subtitle' => match ($type) {
                                        'Tag' => $item->category ? ucfirst($item->category) : null,
                                        'Link' => $item->url ?? null,
                                        'CareerTheme' => $item->description ? \Illuminate\Support\Str::limit($item->description, 120) : null,
                                        default => null,
                                    },
                                    'indent' => 0,
                                ])
                            @endif
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach

        {{-- Confirm button --}}
        @if ($draft->isSelecting())
            <div class="mt-10 pt-6 flex justify-end" style="border-top: 1px solid var(--color-surface-input-border);">
                <form method="POST" action="{{ route('resume-drafts.confirm', $draft) }}">
                    @csrf
                    <button type="submit" class="btn-primary">
                        Confirm selections →
                    </button>
                </form>
            </div>
        @else
            <div class="mt-10 pt-6 text-center text-sm" style="border-top: 1px solid var(--color-surface-input-border); color: var(--color-text-muted);">
                Selections have been confirmed. Draft generation is coming in the next milestone.
            </div>
        @endif
    </div>
@endsection