@extends('layouts.app')

@section('title', 'Select resume content · ' . $jobListing->role_title)

@php
    $totalCount = $draft->selections->count();
    $selectedCount = $draft->selections->where('selected', true)->count();

    // Helper closures for deriving display labels from polymorphic selectables.
    $getTitle = function (\App\Models\ResumeSelection $sel) {
        $item = $sel->selectable;
        $type = class_basename($sel->selectable_type);
        return match ($type) {
            'Position' => $item->title . ' at ' . ($item->organization?->name ?? 'Unknown'),
            'Project' => $item->name,
            'Accomplishment' => $item->title,
            'CareerTheme' => $item->name,
            'Tag' => $item->name,
            'Link' => $item->title ?: $item->url ?: '(untitled link)',
            default => '(unknown)',
        };
    };

    $getSubtitle = function (\App\Models\ResumeSelection $sel) {
        $item = $sel->selectable;
        $type = class_basename($sel->selectable_type);
        return match ($type) {
            'Position' => implode(' · ', array_filter([
                $item->start_date?->format('M Y'),
                $item->end_date ? $item->end_date->format('M Y') : 'Present',
                $item->employment_type ? str_replace('_', ' ', $item->employment_type) : null,
            ])) ?: null,
            'Project' => $item->description ? \Illuminate\Support\Str::limit($item->description, 120) : null,
            'Accomplishment' => $item->impact_metric
                ? implode(' ', array_filter([$item->impact_metric, $item->impact_value, $item->impact_unit]))
                : ($item->description ? \Illuminate\Support\Str::limit($item->description, 120) : null),
            'CareerTheme' => $item->description ? \Illuminate\Support\Str::limit($item->description, 120) : null,
            'Tag' => $item->category ? ucfirst(str_replace('_', ' ', $item->category)) : null,
            'Link' => $item->url ?? null,
            default => null,
        };
    };

    $getTypeBadge = function (\App\Models\ResumeSelection $sel) {
        $type = class_basename($sel->selectable_type);
        return match ($type) {
            'Position' => 'Position',
            'Project' => 'Project',
            'Accomplishment' => 'Accomplishment',
            'CareerTheme' => 'Theme',
            'Tag' => 'Skill',
            'Link' => 'Link',
            default => $type,
        };
    };
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

    <div data-selection-review>
        {{-- Strategy summary — editable --}}
        <section class="mb-10">
            <h2 class="metadata-label mb-2">Strategy</h2>
            <p class="text-xs mb-3" style="color: var(--color-text-muted);">
                The AI's recommended angle for this application. Edit to adjust how your resume will be framed.
            </p>

            @if ($draft->isSelecting())
                <div
                    data-strategy-editor
                    data-strategy-url="{{ route('resume-drafts.update-strategy', $draft) }}"
                    data-strategy-original="{{ $draft->strategy_summary_generated }}"
                >
                    <textarea
                        class="input text-sm leading-relaxed"
                        rows="4"
                        data-strategy-input
                    >{{ $draft->strategy_summary }}</textarea>
                    <div class="flex items-center gap-3 mt-2">
                        <button type="button" class="btn-secondary text-sm" data-strategy-save>
                            Save strategy
                        </button>
                        <button type="button" class="link-subtle text-xs" data-strategy-revert>
                            Revert to original
                        </button>
                        <span
                            class="text-xs"
                            style="color: var(--color-text-muted);"
                            data-strategy-status
                            hidden
                        ></span>
                    </div>
                </div>
            @else
                <div
                    class="rounded-lg border p-4 text-sm leading-relaxed"
                    style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                >
                    {{ $draft->strategy_summary }}
                </div>
            @endif
        </section>

        {{-- Requirements grouped by section: required → preferred → responsibility --}}
        @foreach ($sections as $sectionKey => $section)
            <section class="mb-10">
                <h2 class="text-lg font-semibold mb-4">{{ $section['label'] }}</h2>

                @foreach ($section['requirements'] as $requirement)
                    <div class="mb-6">
                        <div class="flex items-baseline gap-2 mb-1">
                            <h3 class="font-medium">{{ $requirement->title }}</h3>
                            <span
                                class="text-xs px-1.5 py-0.5 rounded"
                                style="background: var(--color-surface-input-border); color: var(--color-text-secondary);"
                            >
                                {{ \App\Enums\RequirementCategory::tryFrom($requirement->category)?->label() ?? $requirement->category }}
                            </span>
                        </div>
                        @if ($requirement->description)
                            <p class="text-sm mb-3" style="color: var(--color-text-muted);">
                                {{ $requirement->description }}
                            </p>
                        @endif

                        @php
                            $reqSelections = $selectionsByRequirement->get($requirement->id, collect());
                        @endphp

                        @if ($reqSelections->isNotEmpty())
                            <div class="space-y-3">
                                @foreach ($reqSelections as $selection)
                                    @if ($selection->selectable)
                                        @include('resume-drafts._selection-card', [
                                            'draft' => $draft,
                                            'selection' => $selection,
                                            'title' => $getTitle($selection),
                                            'subtitle' => $getSubtitle($selection),
                                            'typeBadge' => $getTypeBadge($selection),
                                        ])
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <div
                                class="rounded-lg border border-dashed p-4 text-sm text-center"
                                style="border-color: var(--color-surface-input-border); color: var(--color-text-muted);"
                            >
                                No matching experience in your catalog for this requirement.
                            </div>
                        @endif
                    </div>
                @endforeach
            </section>
        @endforeach

        {{-- Unlinked selections — general resume items --}}
        @if ($unlinkedSelections->isNotEmpty())
            <section class="mb-10">
                <h2 class="text-lg font-semibold mb-2">Other</h2>
                <p class="text-xs mb-4" style="color: var(--color-text-muted);">
                    General items suggested for overall resume strength.
                </p>

                <div class="space-y-3">
                    @foreach ($unlinkedSelections as $selection)
                        @if ($selection->selectable)
                            @include('resume-drafts._selection-card', [
                                'draft' => $draft,
                                'selection' => $selection,
                                'title' => $getTitle($selection),
                                'subtitle' => $getSubtitle($selection),
                                'typeBadge' => $getTypeBadge($selection),
                            ])
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

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