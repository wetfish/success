@extends('layouts.app')

@section('title', 'Review requirements · ' . $jobListing->role_title)

@php
    $totalRequirements = collect($sections)->sum(fn ($s) => $s['requirements']->count());
    $decidedCount = count(array_filter($decisions));
    $acceptedCount = count(array_filter($decisions, fn ($d) => $d === 'accepted'));
    $progressPercent = $totalRequirements > 0 ? (int) round(($decidedCount / $totalRequirements) * 100) : 0;
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
                Review requirements
            </h1>
            <p class="text-sm" style="color: var(--color-text-muted);">
                <span data-triage-decided-count>{{ $decidedCount }}</span>
                of {{ $totalRequirements }} decided
            </p>
        </div>
        <div
            class="h-2 rounded-full overflow-hidden"
            style="background: var(--color-surface-input-border);"
            data-triage-progressbar
            data-total="{{ $totalRequirements }}"
        >
            <div
                class="h-full transition-all"
                style="width: {{ $progressPercent }}%; background: linear-gradient(90deg, rgb(217 70 163 / 0.2), var(--color-accent));"
                data-triage-progressbar-fill
            ></div>
        </div>
    </div>

    <div data-requirement-triage>
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
                    data-strategy-synthesize-url="{{ route('resume-drafts.synthesize-strategy', $draft) }}"
                    data-strategy-original="{{ $draft->strategy_summary_generated }}"
                >
                    <textarea
                        class="input text-sm leading-relaxed"
                        rows="4"
                        data-strategy-input
                    >{{ $draft->strategy_summary }}</textarea>
                    <div class="flex items-center gap-3 mt-2 flex-wrap">
                        <button type="button" class="btn-secondary text-sm" data-strategy-save>
                            Save strategy
                        </button>
                        <button type="button" class="btn-secondary text-sm" data-strategy-synthesize>
                            Synthesize with AI
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
                    <p class="text-xs mt-2" style="color: var(--color-text-muted);">
                        Write your own strategy and click "Synthesize with AI" to combine it with the AI's recommendation.
                    </p>
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

        {{-- Requirements grouped by section --}}
        @foreach ($sections as $sectionKey => $section)
            <section class="mb-10">
                <h2 class="text-lg font-semibold mb-4">{{ $section['label'] }}</h2>

                <div class="space-y-3">
                    @foreach ($section['requirements'] as $requirement)
                        @php
                            $decision = $decisions[$requirement->id] ?? null;
                            $count = $matchCounts[$requirement->id] ?? 0;
                        @endphp

                        <div
                            class="triage-card {{ $decision === 'accepted' ? 'triage-card--accepted' : ($decision === 'rejected' ? 'triage-card--rejected' : '') }}"
                            data-triage-card
                            data-requirement-id="{{ $requirement->id }}"
                            data-decision="{{ $decision ?? '' }}"
                            data-decide-url="{{ route('resume-drafts.decide-requirement', [$draft, $requirement]) }}"
                            data-review-url="{{ route('resume-drafts.requirement', [$draft, $requirement]) }}"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2 mb-1">
                                        <a
                                            href="{{ route('resume-drafts.requirement', [$draft, $requirement]) }}"
                                            class="font-medium link-emphasis {{ $decision === 'accepted' ? '' : 'pointer-events-none' }}"
                                            style="{{ $decision !== 'accepted' ? 'color: inherit; text-decoration: none;' : '' }}"
                                            data-triage-title-link
                                            @if ($decision !== 'accepted') tabindex="-1" @endif
                                        >{{ $requirement->title }}</a>
                                        <span
                                            class="text-xs px-1.5 py-0.5 rounded shrink-0"
                                            style="background: var(--color-surface-input-border); color: var(--color-text-secondary);"
                                        >
                                            {{ \App\Enums\RequirementCategory::tryFrom($requirement->category)?->label() ?? $requirement->category }}
                                        </span>
                                    </div>
                                    @if ($requirement->description)
                                        <p class="text-sm mb-2" style="color: var(--color-text-muted);">
                                            {{ $requirement->description }}
                                        </p>
                                    @endif
                                    <p class="text-xs" style="color: var(--color-text-muted);">
                                        {{ $count }} matching {{ Str::plural('entry', $count) }} in your catalog
                                    </p>
                                </div>

                                {{-- Accept / Reject buttons --}}
                                @if ($draft->isSelecting())
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button
                                            type="button"
                                            class="btn-secondary text-sm"
                                            data-triage-action="accepted"
                                            {{ $decision === 'accepted' ? 'disabled' : '' }}
                                        >
                                            Accept
                                        </button>
                                        <button
                                            type="button"
                                            class="btn-secondary text-sm"
                                            data-triage-action="rejected"
                                            {{ $decision === 'rejected' ? 'disabled' : '' }}
                                        >
                                            Skip
                                        </button>
                                    </div>
                                @endif
                            </div>

                            {{-- Decision badges (shown after decision, hidden by default) --}}
                            <span
                                class="status-badge status-badge-confirmed text-xs mt-2"
                                data-triage-badge="accepted"
                                {{ $decision !== 'accepted' ? 'hidden' : '' }}
                            >
                                Accepted
                            </span>
                            <span
                                class="status-badge status-badge-rejected text-xs mt-2"
                                data-triage-badge="rejected"
                                {{ $decision !== 'rejected' ? 'hidden' : '' }}
                            >
                                Skipped
                            </span>

                            {{-- Error message (hidden by default) --}}
                            <p
                                class="text-xs mt-2"
                                style="color: var(--color-error);"
                                data-triage-error
                                hidden
                            ></p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        {{-- Continue button --}}
        @if ($draft->isSelecting())
            <div class="mt-10 pt-6 flex items-center justify-between" style="border-top: 1px solid var(--color-surface-input-border);">
                <p
                    class="text-sm"
                    style="color: var(--color-text-muted);"
                    data-triage-hint
                >
                    @if ($allDecided)
                        {{ $acceptedCount }} {{ Str::plural('requirement', $acceptedCount) }} accepted — ready to review selections.
                    @else
                        Decide on all requirements to continue.
                    @endif
                </p>

                @php
                    // Find the first accepted requirement to link to.
                    $firstAccepted = null;
                    if ($allDecided && $acceptedCount > 0) {
                        foreach ($sections as $section) {
                            foreach ($section['requirements'] as $req) {
                                if (($decisions[$req->id] ?? null) === 'accepted') {
                                    $firstAccepted = $req;
                                    break 2;
                                }
                            }
                        }
                    }
                @endphp

                <a
                    href="{{ $firstAccepted ? route('resume-drafts.requirement', [$draft, $firstAccepted]) : '#' }}"
                    class="btn-primary {{ $allDecided && $acceptedCount > 0 ? '' : 'opacity-50 pointer-events-none' }}"
                    data-triage-continue
                    @if (! $allDecided || $acceptedCount === 0) aria-disabled="true" tabindex="-1" @endif
                >
                    Continue to review →
                </a>
            </div>
        @endif
    </div>
@endsection