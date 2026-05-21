@extends('layouts.app')

@section('title', $requirement->title . ' · ' . $jobListing->role_title)

@php
    // Helper closures for deriving display labels from polymorphic selectables.
    // Same as the old selections.blade.php — needed by _selection-card.blade.php.
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

    $humanIndex = $currentIndex + 1;

    $sectionLabel = \App\Enums\RequirementSection::tryFrom($requirement->section)?->label() ?? ucfirst($requirement->section);
    $categoryLabel = \App\Enums\RequirementCategory::tryFrom($requirement->category)?->label() ?? $requirement->category;
@endphp

@section('content')
    <div class="mb-2">
        <a href="{{ route('resume-drafts.show', $draft) }}" class="link-subtle text-sm">
            ← Back to requirements triage
        </a>
    </div>

    {{-- Progress bar --}}
    <div class="mb-8">
        <div class="flex items-baseline justify-between mb-2">
            <h1 class="text-2xl font-semibold tracking-tight">
                Review selections
            </h1>
            <p class="text-sm" style="color: var(--color-text-muted);">
                Requirement {{ $humanIndex }} of {{ $totalAccepted }}
            </p>
        </div>
        <div
            class="h-2 rounded-full overflow-hidden"
            style="background: var(--color-surface-input-border);"
        >
            <div
                class="h-full transition-all"
                style="width: {{ $totalAccepted > 0 ? round(($humanIndex / $totalAccepted) * 100) : 0 }}%; background: linear-gradient(90deg, rgb(217 70 163 / 0.2), var(--color-accent));"
            ></div>
        </div>
    </div>

    {{-- Requirement header --}}
    <section class="mb-8">
        <div class="flex items-baseline gap-2 mb-1">
            <h2 class="text-lg font-semibold">{{ $requirement->title }}</h2>
            <span
                class="text-xs px-1.5 py-0.5 rounded shrink-0"
                style="background: var(--color-surface-input-border); color: var(--color-text-secondary);"
            >
                {{ $categoryLabel }}
            </span>
        </div>
        @if ($requirement->description)
            <p class="text-sm" style="color: var(--color-text-muted);">
                {{ $requirement->description }}
            </p>
        @endif
    </section>

    {{-- Selection cards — mounts the existing selection-review.js behavior --}}
    <div data-selection-review>
        <section class="mb-8">
            <h3 class="metadata-label mb-3">AI-suggested catalog entries</h3>

            @if ($selections->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($selections as $selection)
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
                    No AI-suggested entries for this requirement. Use the form below to add experience from your catalog or describe new experience.
                </div>
            @endif
        </section>

        {{-- Search your catalog —  add existing entries --}}
        <section class="mb-8">
            <h3 class="metadata-label mb-2">Search your catalog</h3>
            <p class="text-xs mb-3" style="color: var(--color-text-muted);">
                Find existing organizations, positions, projects, or accomplishments to add to this requirement.
            </p>

            <div
                class="catalog-picker"
                data-catalog-picker
                data-search-url="{{ route('resume-drafts.catalog-search') }}"
            >
                <input
                    type="text"
                    class="input text-sm"
                    placeholder="Search by name or title…"
                    autocomplete="off"
                    data-catalog-picker-input
                    aria-expanded="false"
                >
                <ul
                    class="catalog-picker-dropdown"
                    role="listbox"
                    data-catalog-picker-dropdown
                    hidden
                ></ul>

                {{-- Hidden form — submitted when a result is selected --}}
                <form
                    method="POST"
                    action="{{ route('resume-drafts.add-selection', [$draft, $requirement]) }}"
                    data-catalog-picker-form
                >
                    @csrf
                    <input type="hidden" name="selectable_type" value="">
                    <input type="hidden" name="selectable_id" value="">
                </form>
            </div>
        </section>

        {{-- Add new experience — freeform text --}}
        <section class="mb-8">
            <h3 class="metadata-label mb-2">Add new experience</h3>
            <p class="text-xs mb-3" style="color: var(--color-text-muted);">
                Describe experience related to this requirement that isn't in your catalog yet.
                This will create a new document and take you through the extraction review to add structured entries.
            </p>

            <form
                method="POST"
                action="{{ route('resume-drafts.submit-experience', [$draft, $requirement]) }}"
            >
                @csrf
                <textarea
                    name="experience_text"
                    class="input text-sm leading-relaxed mb-2"
                    rows="4"
                    placeholder="e.g., At my previous role I built a fraud detection system using Python and scikit-learn that reduced chargebacks by 40%…"
                >{{ old('experience_text') }}</textarea>

                @error('experience_text')
                    <p class="text-xs mb-2" style="color: var(--color-error);">{{ $message }}</p>
                @enderror

                <button type="submit" class="btn-secondary text-sm">
                    Save experience
                </button>
            </form>
        </section>
    </div>

    {{-- Navigation --}}
    <div class="mt-10 pt-6 flex items-center justify-between" style="border-top: 1px solid var(--color-surface-input-border);">
        @if ($previousRequirement)
            <a
                href="{{ route('resume-drafts.requirement', [$draft, $previousRequirement]) }}"
                class="btn-secondary text-sm"
            >
                ← Previous
            </a>
        @else
            <a
                href="{{ route('resume-drafts.show', $draft) }}"
                class="btn-secondary text-sm"
            >
                ← Back to triage
            </a>
        @endif

        @if ($nextRequirement)
            <a
                href="{{ route('resume-drafts.requirement', [$draft, $nextRequirement]) }}"
                class="btn-primary text-sm"
            >
                Next →
            </a>
        @else
            <a
                href="{{ route('resume-drafts.confirm-page', $draft) }}"
                class="btn-primary text-sm"
            >
                Review & confirm →
            </a>
        @endif
    </div>
@endsection