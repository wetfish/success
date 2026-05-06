@csrf

@if ($accomplishment->project_id || $project)
    <input type="hidden" name="project_id" value="{{ $accomplishment->project_id ?? $project->id }}">
@endif
@if ($accomplishment->position_id || $position)
    <input type="hidden" name="position_id" value="{{ $accomplishment->position_id ?? $position->id }}">
@endif

@php
    $currentDatingType = old('dating_type',
        $accomplishment->date !== null ? 'date'
        : ($accomplishment->period_start !== null ? 'period' : 'date'));
    $currentConfidence = (int) old('confidence', $accomplishment->confidence ?? 3);
    $currentProminence = (int) old('prominence', $accomplishment->prominence ?? 3);
@endphp

<div class="space-y-8">
    {{-- Description — the main field, larger than other textareas --}}
    <div>
        <label for="description" class="field-label">Description</label>
        <textarea
            id="description"
            name="description"
            rows="6"
            required
            autofocus
            placeholder="What you actually did. One or two clear sentences are usually best — this is what becomes a resume bullet."
            class="input @error('description') has-error @enderror"
        >{{ old('description', $accomplishment->description) }}</textarea>
        @error('description')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Dating: single date or period --}}
    <div class="space-y-5">
        <h2 class="section-heading">When did this happen?</h2>

        <div class="flex items-center gap-6 text-sm">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input
                    type="radio"
                    name="dating_type"
                    value="date"
                    @checked($currentDatingType === 'date')
                    style="accent-color: var(--color-accent);"
                    class="dating-type-radio"
                >
                <span>Single date</span>
            </label>
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input
                    type="radio"
                    name="dating_type"
                    value="period"
                    @checked($currentDatingType === 'period')
                    style="accent-color: var(--color-accent);"
                    class="dating-type-radio"
                >
                <span>Period of time</span>
            </label>
        </div>

        <div id="dating_inputs_date" @class(['hidden' => $currentDatingType !== 'date'])>
            <label for="date" class="field-label">Date</label>
            <input
                type="date"
                id="date"
                name="date"
                value="{{ old('date', $accomplishment->date?->format('Y-m-d')) }}"
                class="input @error('date') has-error @enderror"
                @disabled($currentDatingType !== 'date')
            >
            @error('date')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div id="dating_inputs_period" @class(['hidden' => $currentDatingType !== 'period'])>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="period_start" class="field-label">Period start</label>
                    <input
                        type="date"
                        id="period_start"
                        name="period_start"
                        value="{{ old('period_start', $accomplishment->period_start?->format('Y-m-d')) }}"
                        class="input @error('period_start') has-error @enderror"
                        @disabled($currentDatingType !== 'period')
                    >
                    @error('period_start')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="period_end" class="field-label">
                        Period end
                        <span class="field-label-hint">(blank if ongoing)</span>
                    </label>
                    <input
                        type="date"
                        id="period_end"
                        name="period_end"
                        value="{{ old('period_end', $accomplishment->period_end?->format('Y-m-d')) }}"
                        class="input @error('period_end') has-error @enderror"
                        @disabled($currentDatingType !== 'period')
                    >
                    @error('period_end')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    {{-- Impact trio: metric, value, unit --}}
    <div class="space-y-5">
        <div>
            <h2 class="section-heading">Impact (optional)</h2>
            <p class="field-help mt-2">
                Capture measurable impact when you have it. Useful for resume bullets that need numbers.
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
            <div>
                <label for="impact_metric" class="field-label">Metric</label>
                <input
                    type="text"
                    id="impact_metric"
                    name="impact_metric"
                    value="{{ old('impact_metric', $accomplishment->impact_metric) }}"
                    placeholder="p99 latency"
                    class="input @error('impact_metric') has-error @enderror"
                >
                @error('impact_metric')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="impact_value" class="field-label">Value</label>
                <input
                    type="text"
                    id="impact_value"
                    name="impact_value"
                    value="{{ old('impact_value', $accomplishment->impact_value) }}"
                    placeholder="47"
                    class="input @error('impact_value') has-error @enderror"
                >
                @error('impact_value')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="impact_unit" class="field-label">Unit</label>
                <input
                    type="text"
                    id="impact_unit"
                    name="impact_unit"
                    value="{{ old('impact_unit', $accomplishment->impact_unit) }}"
                    placeholder="percent reduction"
                    class="input @error('impact_unit') has-error @enderror"
                >
                @error('impact_unit')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    {{-- Confidence and prominence sliders --}}
    <div class="space-y-6">
        <div>
            <h2 class="section-heading">Scoring</h2>
            <p class="field-help mt-2">
                These scores affect how the AI weights this accomplishment when generating tailored resumes.
            </p>
        </div>

        <div>
            <div class="flex items-baseline justify-between mb-2">
                <label for="confidence" class="field-label mb-0">Confidence</label>
                <span
                    id="confidence_label"
                    class="text-sm slider-label-{{ $currentConfidence }}"
                >
                    {{ $confidenceLabels[$currentConfidence] ?? '' }}
                </span>
            </div>
            <input
                type="range"
                id="confidence"
                name="confidence"
                min="1"
                max="5"
                step="1"
                value="{{ $currentConfidence }}"
                class="slider-track confidence-slider"
                style="--slider-fill: {{ ($currentConfidence - 1) * 25 }}%;"
            >
            <p class="field-help">
                How sure you are about the impact data. Verified means corroborated; rough estimate means working memory.
            </p>
            @error('confidence')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <div class="flex items-baseline justify-between mb-2">
                <label for="prominence" class="field-label mb-0">Prominence</label>
                <span
                    id="prominence_label"
                    class="text-sm slider-label-{{ $currentProminence }}"
                >
                    {{ $prominenceLabels[$currentProminence] ?? '' }}
                </span>
            </div>
            <input
                type="range"
                id="prominence"
                name="prominence"
                min="1"
                max="5"
                step="1"
                value="{{ $currentProminence }}"
                class="slider-track prominence-slider"
                style="--slider-fill: {{ ($currentProminence - 1) * 25 }}%;"
            >
            <p class="field-help">
                How prominently this should appear on a tailored resume. Featured means hero-tier; background means it's there for completeness.
            </p>
            @error('prominence')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Context and notes --}}
    <div class="space-y-5">
        <h2 class="section-heading">Context (optional)</h2>

        <div>
            <label for="context_notes" class="field-label">
                Context notes
                <span class="field-label-hint">(private — never goes on a resume)</span>
            </label>
            <textarea
                id="context_notes"
                name="context_notes"
                rows="4"
                placeholder="Anything that shaped this — collaborators, constraints, what made it hard or special"
                class="input @error('context_notes') has-error @enderror"
            >{{ old('context_notes', $accomplishment->context_notes) }}</textarea>
            @error('context_notes')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>
    </div>
</div>

<script>
    (function () {
        // Dating type toggle: show only the matching date input group,
        // disable the others so their stale values don't get submitted
        // alongside the active group's values.
        const datingRadios = document.querySelectorAll('.dating-type-radio');
        const datingGroups = {
            date: document.getElementById('dating_inputs_date'),
            period: document.getElementById('dating_inputs_period'),
        };

        function updateDatingVisibility() {
            const selected = document.querySelector('.dating-type-radio:checked')?.value;
            Object.entries(datingGroups).forEach(([key, group]) => {
                if (! group) return;
                const matches = key === selected;
                group.classList.toggle('hidden', ! matches);
                group.querySelectorAll('input').forEach((el) => {
                    el.disabled = ! matches;
                });
            });
        }

        datingRadios.forEach((radio) => radio.addEventListener('change', updateDatingVisibility));

        // Slider behavior: each slider updates a label element and its
        // own background-size variable as the value changes. The label's
        // class swaps too, so the color and weight track the value.
        function setupSlider(slider, labelElement, labels) {
            if (! slider || ! labelElement) return;

            function update() {
                const value = parseInt(slider.value, 10);
                labelElement.textContent = labels[value] || '';
                // Update label color/weight class (1-5).
                labelElement.className = labelElement.className
                    .replace(/slider-label-\d/g, '')
                    .trim() + ' slider-label-' + value;
                // Update fill percentage on the track.
                slider.style.setProperty('--slider-fill', ((value - 1) * 25) + '%');
            }

            slider.addEventListener('input', update);
            slider.addEventListener('change', update);
        }

        const confidenceLabels = @json($confidenceLabels);
        const prominenceLabels = @json($prominenceLabels);

        setupSlider(
            document.getElementById('confidence'),
            document.getElementById('confidence_label'),
            confidenceLabels,
        );

        setupSlider(
            document.getElementById('prominence'),
            document.getElementById('prominence_label'),
            prominenceLabels,
        );
    })();
</script>