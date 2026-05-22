@extends('layouts.app')

@section('title', 'Confirm selections · ' . $jobListing->role_title)

@php
    $synthesizeUrl = route('resume-drafts.synthesize-notes', $draft);
    $saveStrategyUrl = route('resume-drafts.update-strategy', $draft);
@endphp

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
            Review your notes and strategy before generating. Use "Synthesize from notes" to incorporate
            what you learned during review into the strategy.
        </p>
    </div>

    {{-- Strategy summary --}}
    <section class="mb-10" data-confirm-strategy>
        <h2 class="metadata-label mb-2">Strategy</h2>

        @if ($draft->isSelecting())
            <p class="text-xs mb-3" style="color: var(--color-text-muted);">
                This guides the overall framing of your resume. Update it to reflect what you discovered during review.
            </p>

            <textarea
                class="input text-sm leading-relaxed"
                rows="4"
                data-strategy-textarea
            >{{ $draft->strategy_summary }}</textarea>

            <div class="flex items-center gap-3 mt-2 flex-wrap">
                <button type="button" class="btn-secondary text-sm" data-strategy-save>
                    Save strategy
                </button>
                <button type="button" class="btn-secondary text-sm" data-strategy-synthesize>
                    Synthesize from notes
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
        @else
            <div
                class="rounded-lg border p-4 text-sm leading-relaxed"
                style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
            >
                {{ $draft->strategy_summary }}
            </div>
        @endif
    </section>

    {{-- Accepted requirements with selections and notes --}}
    <section class="mb-8">
        <h2 class="metadata-label mb-3">Accepted requirements & your notes</h2>

        @if ($acceptedRequirements->isNotEmpty())
            <div class="space-y-4">
                @foreach ($acceptedRequirements as $requirement)
                    @php
                        $included = $includedCounts[$requirement->id] ?? 0;
                        $experiences = $experienceCounts[$requirement->id] ?? 0;
                        $categoryLabel = \App\Enums\RequirementCategory::tryFrom($requirement->category)?->label() ?? $requirement->category;

                        // Gather selections from this requirement and any duplicates.
                        $decisions = $draft->requirement_decisions ?? [];
                        $reqSelections = collect($selections->get($requirement->id, collect()));
                        $dupIds = collect($decisions)
                            ->filter(fn ($d) => is_array($d) && ($d['duplicate_of'] ?? null) === $requirement->id)
                            ->keys();
                        foreach ($dupIds as $dupId) {
                            $reqSelections = $reqSelections->merge($selections->get($dupId, collect()));
                        }
                        $notedSelections = $reqSelections->filter(fn ($s) => $s->user_relevance_note);
                    @endphp

                    <div
                        class="rounded-lg border p-4"
                        style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                    >
                        <div class="flex items-start justify-between gap-4 mb-2">
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
                                @if (! empty($duplicatesMap[$requirement->id]))
                                    <p class="text-xs mt-1" style="color: var(--color-text-muted);">
                                        Also addresses: {{ implode(', ', $duplicatesMap[$requirement->id]) }}
                                    </p>
                                @endif
                            </div>

                            <a
                                href="{{ route('resume-drafts.requirement', [$draft, $requirement]) }}"
                                class="link-subtle text-xs shrink-0"
                            >
                                Edit →
                            </a>
                        </div>

                        {{-- Selections with notes --}}
                        @if ($reqSelections->isNotEmpty())
                            <div class="mt-3 space-y-2" style="border-top: 1px solid var(--color-divider); padding-top: 0.75rem;">
                                @foreach ($reqSelections as $selection)
                                    @php
                                        $selectable = $selection->selectable;
                                        $selName = $selectable->title ?? $selectable->name ?? '[unknown]';
                                        $selType = class_basename($selectable);
                                    @endphp

                                    <div class="text-sm">
                                        <div class="flex items-baseline gap-2">
                                            <span class="font-medium">{{ $selName }}</span>
                                            <span
                                                class="text-xs px-1 py-0.5 rounded"
                                                style="background: var(--color-surface-input-border); color: var(--color-text-muted);"
                                            >{{ $selType }}</span>
                                        </div>

                                        @if ($selection->user_relevance_note)
                                            <p class="mt-1 text-sm leading-relaxed" style="color: var(--color-text-secondary);">
                                                <span class="font-medium" style="color: var(--color-accent);">Your note:</span>
                                                {{ $selection->user_relevance_note }}
                                            </p>
                                        @elseif ($selection->ai_reasoning)
                                            <p class="mt-1 text-sm leading-relaxed" style="color: var(--color-text-muted);">
                                                <span class="font-medium">AI:</span>
                                                {{ $selection->ai_reasoning }}
                                            </p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
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

    {{-- Confirm button (selecting only) or back link (other statuses) --}}
    @if ($draft->isSelecting() && $acceptedRequirements->isNotEmpty())
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
    @elseif (! $draft->isSelecting())
        <div class="mt-10 pt-6" style="border-top: 1px solid var(--color-surface-input-border);">
            <a href="{{ route('resume-drafts.edit', $draft) }}" class="link-subtle text-sm">
                ← Back to draft
            </a>
        </div>
    @endif

    {{-- Strategy editor controller --}}
    <script>
        (function () {
            var root = document.querySelector('[data-confirm-strategy]');
            if (!root) return;

            var textarea = root.querySelector('[data-strategy-textarea]');
            var saveBtn = root.querySelector('[data-strategy-save]');
            var synthesizeBtn = root.querySelector('[data-strategy-synthesize]');
            var revertBtn = root.querySelector('[data-strategy-revert]');
            var status = root.querySelector('[data-strategy-status]');
            var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            var originalStrategy = @json($draft->strategy_summary_generated);
            var saveUrl = @json($saveStrategyUrl);
            var synthesizeUrl = @json($synthesizeUrl);

            function showStatus(text) {
                status.textContent = text;
                status.hidden = false;
            }

            function hideStatus() {
                status.hidden = true;
            }

            saveBtn.addEventListener('click', function () {
                showStatus('Saving…');
                fetch(saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ strategy_summary: textarea.value }),
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    showStatus(data.ok ? 'Saved' : (data.error || 'Save failed'));
                    setTimeout(hideStatus, 2000);
                })
                .catch(function () {
                    showStatus('Save failed');
                    setTimeout(hideStatus, 3000);
                });
            });

            synthesizeBtn.addEventListener('click', function () {
                showStatus('Synthesizing from your notes…');
                synthesizeBtn.disabled = true;
                fetch(synthesizeUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({}),
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok && data.synthesized) {
                        textarea.value = data.synthesized;
                        showStatus('Strategy updated from notes — review and save when ready.');
                    } else {
                        showStatus(data.error || 'Synthesis failed');
                    }
                    synthesizeBtn.disabled = false;
                    setTimeout(hideStatus, 4000);
                })
                .catch(function () {
                    showStatus('Synthesis failed — try again.');
                    synthesizeBtn.disabled = false;
                    setTimeout(hideStatus, 3000);
                });
            });

            revertBtn.addEventListener('click', function () {
                textarea.value = originalStrategy;
                showStatus('Reverted to original — save to persist.');
                setTimeout(hideStatus, 3000);
            });
        })();
    </script>
@endsection