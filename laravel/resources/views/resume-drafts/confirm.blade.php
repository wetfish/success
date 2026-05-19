@extends('layouts.app')

@section('title', 'Confirm selections · ' . $jobListing->role_title)

@section('content')
    <div class="mb-2">
        <a href="{{ route('resume-drafts.show', $draft) }}" class="link-subtle text-sm">
            ← Back to requirements triage
        </a>
    </div>

    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight">
            Confirm & generate
        </h1>
        <p class="text-sm mt-1" style="color: var(--color-text-muted);">
            Review your selections before generating the resume draft.
        </p>
    </div>

    {{-- Strategy summary — read-only --}}
    <section class="mb-8">
        <h2 class="metadata-label mb-2">Strategy</h2>
        <div
            class="rounded-lg border p-4 text-sm leading-relaxed"
            style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
        >
            {{ $draft->strategy_summary }}
        </div>
    </section>

    {{-- Accepted requirements summary --}}
    <section class="mb-8">
        <h2 class="metadata-label mb-3">Accepted requirements</h2>

        @if ($acceptedRequirements->isNotEmpty())
            <div class="space-y-3">
                @foreach ($acceptedRequirements as $requirement)
                    @php
                        $included = $includedCounts[$requirement->id] ?? 0;
                        $experiences = $experienceCounts[$requirement->id] ?? 0;
                        $categoryLabel = \App\Enums\RequirementCategory::tryFrom($requirement->category)?->label() ?? $requirement->category;
                    @endphp

                    <div
                        class="rounded-lg border p-4"
                        style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex items-baseline gap-2 mb-1">
                                    <h3 class="font-medium">{{ $requirement->title }}</h3>
                                    <span
                                        class="text-xs px-1.5 py-0.5 rounded shrink-0"
                                        style="background: var(--color-surface-input-border); color: var(--color-text-secondary);"
                                    >
                                        {{ $categoryLabel }}
                                    </span>
                                </div>
                                <p class="text-sm" style="color: var(--color-text-muted);">
                                    {{ $included }} included {{ Str::plural('entry', $included) }}@if ($experiences > 0), {{ $experiences }} freeform {{ Str::plural('response', $experiences) }}@endif
                                </p>
                            </div>

                            <a
                                href="{{ route('resume-drafts.requirement', [$draft, $requirement]) }}"
                                class="link-subtle text-xs shrink-0"
                            >
                                Edit →
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div
                class="rounded-lg border border-dashed p-4 text-sm text-center"
                style="border-color: var(--color-surface-input-border); color: var(--color-text-muted);"
            >
                No requirements have been accepted.
                <a href="{{ route('resume-drafts.show', $draft) }}" class="link-emphasis">Go back to triage</a>
                to accept at least one.
            </div>
        @endif
    </section>

    {{-- Confirm button --}}
    @if ($acceptedRequirements->isNotEmpty())
        <div class="mt-10 pt-6 flex items-center justify-between" style="border-top: 1px solid var(--color-surface-input-border);">
            <p class="text-sm" style="color: var(--color-text-muted);">
                @php
                    $totalIncluded = array_sum($includedCounts);
                @endphp
                {{ $totalIncluded }} catalog {{ Str::plural('entry', $totalIncluded) }} across
                {{ $acceptedRequirements->count() }} {{ Str::plural('requirement', $acceptedRequirements->count()) }}.
            </p>

            <form method="POST" action="{{ route('resume-drafts.confirm', $draft) }}">
                @csrf
                <button type="submit" class="btn-primary">
                    Generate resume draft →
                </button>
            </form>
        </div>
    @endif
@endsection