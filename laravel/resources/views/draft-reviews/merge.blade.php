@extends('layouts.app')

@section('title', 'Merge draft — Success')

@php
    // Display name pulled off the target — orgs and projects expose
    // it as `name`, positions as `title`. Same convention used by
    // DraftMerger::canonicalNameOf().
    $displayName = function ($record) {
        if ($record instanceof \App\Models\Position) {
            return $record->title;
        }
        return $record->name ?? '(unnamed)';
    };

    $typeLabels = [
        'organization' => 'Organization',
        'position' => 'Position',
        'project' => 'Project',
        'accomplishment' => 'Accomplishment',
    ];
    $typeLabel = $typeLabels[$draft->record_type] ?? ucfirst($draft->record_type);

    $payload = $draft->payload ?? [];

    $draftReviewUrl = route('source-documents.review.show', [
        'sourceDocument' => $sourceDocument,
        'draft' => $draft->id,
    ]);
@endphp

@section('content')
    <div class="mb-2">
        <a href="{{ $draftReviewUrl }}" class="link-subtle text-sm">
            ← Back to draft
        </a>
    </div>

    <h1 class="text-2xl font-semibold tracking-tight mb-2">
        Merge {{ strtolower($typeLabel) }} draft
    </h1>

    @if (session('status'))
        <div class="status-banner mb-6">{{ session('status') }}</div>
    @endif

    @if ($candidate === null)
        {{-- ==================================================
             PICKER MODE — render a list of candidate records.
             Each entry is a link to this same route with the
             chosen candidate_id, which lands the user in the
             editor mode below.
             ================================================== --}}
        <p class="mb-6" style="color: var(--color-text-muted);">
            The AI extracted a {{ strtolower($typeLabel) }} that looks similar
            to {{ $candidates->count() === 1 ? 'one existing record' : $candidates->count() . ' existing records' }}.
            Pick the one to merge into.
        </p>

        <ul class="space-y-2 mb-8">
            @foreach ($candidates as $c)
                <li>
                    <a
                        href="{{ route('source-documents.review.merge.show', [
                            'sourceDocument' => $sourceDocument,
                            'draft' => $draft->id,
                            'candidate_id' => $c->id,
                        ]) }}"
                        class="list-row block"
                    >
                        <span class="font-medium">{{ $displayName($c) }}</span>
                        @if ($c instanceof \App\Models\Organization)
                            <span class="text-sm ml-2" style="color: var(--color-text-muted);">
                                {{ str_replace('_', ' ', $c->type) }}@if ($c->headquarters), {{ $c->headquarters }}@endif
                            </span>
                        @elseif ($c instanceof \App\Models\Position)
                            <span class="text-sm ml-2" style="color: var(--color-text-muted);">
                                at {{ $c->organization->name ?? '(unknown org)' }}
                                @if ($c->start_date)
                                    · {{ $c->start_date->format('M Y') }}
                                    @if ($c->end_date)
                                        – {{ $c->end_date->format('M Y') }}
                                    @else
                                        – present
                                    @endif
                                @endif
                            </span>
                        @elseif ($c instanceof \App\Models\Project)
                            <span class="text-sm ml-2" style="color: var(--color-text-muted);">
                                at {{ $c->organization->name ?? '(unknown org)' }}
                            </span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="flex items-center justify-between gap-3 pt-6 border-t" style="border-color: var(--color-divider);">
            <a href="{{ $draftReviewUrl }}" class="link-subtle text-sm">
                Cancel — back to draft
            </a>
        </div>
    @else
        {{-- ==================================================
             EDITOR MODE — side-by-side per-field chooser.
             For each schema field that maps to a column on the
             target model, render existing|draft (and a third
             synthesized column for textarea fields). Hidden
             inputs hold the currently-chosen value, defaulting
             to existing; JS toggles them as the user picks.
             ================================================== --}}
        @php
            $targetFillable = $candidate->getFillable();
            $targetName = $displayName($candidate);

            // Pre-compute the synthesize endpoint URL so the inline
            // script can use `@json($synthesizeUrl)` rather than
            // `@json(route(..., [...]))`. Blade directives parse
            // their argument expression by regex and don't handle
            // multi-line arrays reliably; passing a plain variable
            // sidesteps the issue.
            $synthesizeUrl = route('source-documents.review.merge.synthesize', [
                'sourceDocument' => $sourceDocument,
                'draft' => $draft->id,
            ]);
        @endphp

        <p class="mb-6" style="color: var(--color-text-muted);">
            Merging into
            <span class="font-medium" style="color: var(--color-text-primary);">{{ $targetName }}</span>.
            For each field, pick the value that should win on the existing record.
            Unchanged fields keep their current value.
        </p>

        <form
            action="{{ route('source-documents.review.merge.store', [
                'sourceDocument' => $sourceDocument,
                'draft' => $draft->id,
            ]) }}"
            method="POST"
            data-merge-form
        >
            @csrf
            <input type="hidden" name="candidate_id" value="{{ $candidate->id }}">

            <div class="space-y-6">
                @foreach ($fieldSchema as $key => $config)
                    @if (in_array($key, $targetFillable, true))
                        @php
                            // Pull the existing value off the target.
                            // Dates come back as Carbon instances — flatten
                            // to ISO date string for display + transport.
                            $existingRaw = $candidate->{$key};
                            if ($existingRaw instanceof \DateTimeInterface) {
                                $existingRaw = $existingRaw->format('Y-m-d');
                            }
                            $existingValue = $existingRaw === null ? '' : (string) $existingRaw;

                            $draftRaw = $payload[$key] ?? null;
                            $draftValue = $draftRaw === null ? '' : (string) $draftRaw;

                            $isTextarea = ($config['type'] ?? 'text') === 'textarea';
                            $required = $config['required'] ?? false;

                            $existingIsEmpty = $existingValue === '';
                            $draftIsEmpty = $draftValue === '';
                            $hasMultiline =
                                str_contains($existingValue, "\n")
                                || str_contains($draftValue, "\n");

                            $colsClass = $isTextarea ? 'sm:grid-cols-3' : 'sm:grid-cols-2';
                        @endphp

                        <div data-merge-field-row>
                            <div class="metadata-label mb-2">
                                {{ $config['label'] }}
                                @if ($required)
                                    <span style="color: var(--color-accent);" aria-label="required">*</span>
                                @endif
                            </div>

                            {{-- Hidden input carrying the currently-chosen
                                 value. Defaults to existing so a no-touch
                                 merge is a no-op on the field. --}}
                            <input
                                type="hidden"
                                name="fields[{{ $key }}]"
                                value="{{ $existingValue }}"
                                data-merge-value-input
                            >

                            <div class="grid grid-cols-1 {{ $colsClass }} gap-3">
                                {{-- Existing column — chosen by default. --}}
                                <div class="merge-cell is-chosen" data-merge-cell>
                                    <div class="merge-cell-label">Existing</div>
                                    <div class="merge-cell-value{{ ($isTextarea || $hasMultiline) ? ' is-multiline' : '' }}{{ $existingIsEmpty ? ' is-empty' : '' }}">{{ $existingIsEmpty ? '(empty)' : $existingValue }}</div>
                                    <div class="merge-cell-actions">
                                        <button
                                            type="button"
                                            class="btn-secondary"
                                            data-merge-pick
                                            data-merge-pick-value="{{ $existingValue }}"
                                        >
                                            Use existing
                                        </button>
                                    </div>
                                </div>

                                {{-- Draft column. --}}
                                <div class="merge-cell" data-merge-cell>
                                    <div class="merge-cell-label">Draft</div>
                                    <div class="merge-cell-value{{ ($isTextarea || $hasMultiline) ? ' is-multiline' : '' }}{{ $draftIsEmpty ? ' is-empty' : '' }}">{{ $draftIsEmpty ? '(empty)' : $draftValue }}</div>
                                    <div class="merge-cell-actions">
                                        <button
                                            type="button"
                                            class="btn-secondary"
                                            data-merge-pick
                                            data-merge-pick-value="{{ $draftValue }}"
                                            @if ($required && $draftIsEmpty)
                                                disabled
                                                title="The draft is empty for this required field"
                                                style="opacity: 0.4; cursor: not-allowed;"
                                            @endif
                                        >
                                            Use draft
                                        </button>
                                    </div>
                                </div>

                                @if ($isTextarea)
                                    {{-- Synthesized column — populates on
                                         demand when the user clicks the
                                         Synthesize button. Disabled when
                                         both source values are empty —
                                         nothing meaningful to combine. --}}
                                    <div class="merge-cell" data-merge-cell>
                                        <div class="merge-cell-label">Synthesized</div>
                                        <div class="merge-cell-value is-multiline is-empty" data-merge-synth-display></div>
                                        <div class="merge-cell-actions">
                                            <button
                                                type="button"
                                                class="btn-secondary"
                                                data-merge-synthesize
                                                data-merge-existing="{{ $existingValue }}"
                                                data-merge-draft="{{ $draftValue }}"
                                                @if ($existingIsEmpty && $draftIsEmpty)
                                                    disabled
                                                    title="Need at least one source value to synthesize"
                                                    style="opacity: 0.4; cursor: not-allowed;"
                                                @endif
                                            >
                                                Synthesize
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="flex items-center justify-between gap-3 mt-8 pt-6 border-t" style="border-color: var(--color-divider);">
                <a href="{{ $draftReviewUrl }}" class="link-subtle text-sm">
                    Cancel
                </a>
                <button type="submit" class="btn-primary">
                    Merge into {{ $targetName }}
                </button>
            </div>
        </form>

        {{-- Editor controller. Single delegated click handler for
             pick buttons; per-button async handler for synthesize.
             Plain DOM, IIFE-scoped, no dependencies. --}}
        <script>
            (function () {
                const form = document.querySelector('[data-merge-form]');
                if (!form) return;

                const csrfTokenInput = form.querySelector('input[name="_token"]');
                const csrfToken = csrfTokenInput ? csrfTokenInput.value : '';
                const synthesizeUrl = @json($synthesizeUrl);

                // Delegated handler for all pick buttons (existing,
                // draft, and any synthesized buttons added after a
                // successful synthesis call).
                form.addEventListener('click', function (e) {
                    const pickBtn = e.target.closest('[data-merge-pick]');
                    if (!pickBtn || !form.contains(pickBtn)) return;

                    const row = pickBtn.closest('[data-merge-field-row]');
                    if (!row) return;

                    const hidden = row.querySelector('[data-merge-value-input]');
                    if (hidden) {
                        hidden.value = pickBtn.dataset.mergePickValue || '';
                    }

                    row.querySelectorAll('[data-merge-cell]').forEach(function (cell) {
                        cell.classList.remove('is-chosen');
                    });
                    const chosenCell = pickBtn.closest('[data-merge-cell]');
                    if (chosenCell) chosenCell.classList.add('is-chosen');
                });

                // Synthesize handler. On success, replace the
                // synthesize button with a "Use synthesized" pick
                // button so the user can choose the result via the
                // same click handler above.
                form.querySelectorAll('[data-merge-synthesize]').forEach(function (btn) {
                    btn.addEventListener('click', async function () {
                        const cell = btn.closest('[data-merge-cell]');
                        const display = cell.querySelector('[data-merge-synth-display]');

                        btn.disabled = true;
                        btn.textContent = 'Synthesizing…';
                        if (display) {
                            display.textContent = '';
                            display.classList.remove('is-empty');
                            display.style.color = '';
                        }

                        try {
                            const res = await fetch(synthesizeUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({
                                    existing: btn.dataset.mergeExisting || '',
                                    draft: btn.dataset.mergeDraft || '',
                                }),
                            });

                            if (!res.ok) {
                                let message = 'Synthesis failed';
                                try {
                                    const errBody = await res.json();
                                    if (errBody && errBody.error) message = errBody.error;
                                } catch (_) { /* ignore parse errors */ }
                                throw new Error(message);
                            }

                            const data = await res.json();
                            const synthesized = (data && data.synthesized) || '';

                            if (display) display.textContent = synthesized;

                            // Replace the synthesize button with a
                            // pick button. Use DOM construction (not
                            // innerHTML) so synthesized text can't
                            // inject markup.
                            const useBtn = document.createElement('button');
                            useBtn.type = 'button';
                            useBtn.className = 'btn-secondary';
                            useBtn.textContent = 'Use synthesized';
                            useBtn.setAttribute('data-merge-pick', '');
                            useBtn.setAttribute('data-merge-pick-value', synthesized);
                            btn.replaceWith(useBtn);
                        } catch (err) {
                            if (display) {
                                display.textContent = (err && err.message) || 'Synthesis failed';
                                display.style.color = 'var(--color-error)';
                            }
                            btn.disabled = false;
                            btn.textContent = 'Retry synthesize';
                        }
                    });
                });
            })();
        </script>
    @endif
@endsection