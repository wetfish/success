@csrf

{{-- Hidden context fields. These are locked at create time based on
     where the form was reached from. On edit, they're preserved as-is. --}}
<input type="hidden" name="organization_id" value="{{ $project->organization_id }}">
@if ($project->position_id)
    <input type="hidden" name="position_id" value="{{ $project->position_id }}">
@endif
@if ($project->parent_project_id)
    <input type="hidden" name="parent_project_id" value="{{ $project->parent_project_id }}">
@endif

@php
    // Helper to derive precision-specific values from a stored date for
    // pre-filling the edit form. The stored date is the start-of-period
    // or end-of-period; we extract the precision-appropriate piece.
    $extractMonth = fn ($date) => $date?->format('Y-m');
    $extractYear = fn ($date) => $date?->format('Y');
    $extractQuarter = fn ($date) => $date ? (int) ceil($date->format('n') / 3) : null;
    $extractDay = fn ($date) => $date?->format('Y-m-d');

    $currentPrecision = old('date_precision', $project->date_precision ?? '');
    $hasPublicName = old('public_name', $project->public_name) !== null;
@endphp

<div class="space-y-8">
    {{-- Required basics --}}
    <div class="space-y-5">
        <div>
            <label for="name" class="field-label">Name</label>
            <input
                type="text"
                id="name"
                name="name"
                value="{{ old('name', $project->name) }}"
                required
                autofocus
                placeholder="What you call this project internally"
                class="input @error('name') has-error @enderror"
            >
            @error('name')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Public name is hidden behind a checkbox for the niche case
             where a project's public name differs from its internal one
             (codename projects, open-source projects with marketing names). --}}
        <div>
            <label class="inline-flex items-center gap-2 text-sm cursor-pointer" style="color: var(--color-text-primary);">
                <input
                    type="checkbox"
                    id="has_public_name_toggle"
                    @checked($hasPublicName)
                    class="rounded"
                    style="accent-color: var(--color-accent);"
                >
                <span>This project has a different public name</span>
            </label>

            <div id="public_name_wrapper" @class(['mt-3' => true, 'hidden' => ! $hasPublicName])>
                <label for="public_name" class="field-label">Public name</label>
                <input
                    type="text"
                    id="public_name"
                    name="public_name"
                    value="{{ old('public_name', $project->public_name) }}"
                    placeholder="The name used in marketing or external comms"
                    class="input @error('public_name') has-error @enderror"
                >
                @error('public_name')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label for="visibility" class="field-label">Visibility</label>
                <select
                    id="visibility"
                    name="visibility"
                    required
                    class="input @error('visibility') has-error @enderror"
                >
                    <option value="">Choose one…</option>
                    @foreach ($visibilities as $visibility)
                        <option
                            value="{{ $visibility }}"
                            @selected(old('visibility', $project->visibility) === $visibility)
                        >
                            {{ ucfirst(str_replace('_', ' ', $visibility)) }}
                        </option>
                    @endforeach
                </select>
                @error('visibility')
                    <p class="field-error">{{ $message }}</p>
                @enderror
                <p class="field-help">
                    Public, open source, internal, or confidential.
                </p>
            </div>

            <div>
                <label for="contribution_level" class="field-label">Your role</label>
                <select
                    id="contribution_level"
                    name="contribution_level"
                    required
                    class="input @error('contribution_level') has-error @enderror"
                >
                    <option value="">Choose one…</option>
                    @foreach ($contributionLevels as $level)
                        <option
                            value="{{ $level }}"
                            @selected(old('contribution_level', $project->contribution_level) === $level)
                        >
                            {{ ucfirst($level) }}
                        </option>
                    @endforeach
                </select>
                @error('contribution_level')
                    <p class="field-error">{{ $message }}</p>
                @enderror
                <p class="field-help">
                    How central you were to this work — lead, core, contributor, occasional, or reviewer.
                </p>
            </div>
        </div>
    </div>

    {{-- Project story (the architecturally important section) --}}
    <div class="space-y-5">
        <div>
            <h2 class="section-heading">Project story (optional — fill in as much as you remember)</h2>
            <p class="field-help mt-2">
                These fields capture the shape of the work — the why and how, not just the what. The AI uses them when generating tailored resume text.
            </p>
        </div>

        <div>
            <label for="description" class="field-label">Description</label>
            <textarea
                id="description"
                name="description"
                rows="2"
                placeholder="One line: what is this thing?"
                class="input @error('description') has-error @enderror"
            >{{ old('description', $project->description) }}</textarea>
            @error('description')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="problem" class="field-label">Problem</label>
            <textarea
                id="problem"
                name="problem"
                rows="3"
                placeholder="What was broken or missing? What pain were you addressing?"
                class="input @error('problem') has-error @enderror"
            >{{ old('problem', $project->problem) }}</textarea>
            @error('problem')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="constraints" class="field-label">Constraints</label>
            <textarea
                id="constraints"
                name="constraints"
                rows="3"
                placeholder="What you couldn't do, and why — technical, organizational, or otherwise"
                class="input @error('constraints') has-error @enderror"
            >{{ old('constraints', $project->constraints) }}</textarea>
            @error('constraints')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="approach" class="field-label">Approach</label>
            <textarea
                id="approach"
                name="approach"
                rows="3"
                placeholder="How you tackled it"
                class="input @error('approach') has-error @enderror"
            >{{ old('approach', $project->approach) }}</textarea>
            @error('approach')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="outcome" class="field-label">Outcome</label>
            <textarea
                id="outcome"
                name="outcome"
                rows="3"
                placeholder="What happened — measurable impact when possible"
                class="input @error('outcome') has-error @enderror"
            >{{ old('outcome', $project->outcome) }}</textarea>
            @error('outcome')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="rationale" class="field-label">Rationale</label>
            <textarea
                id="rationale"
                name="rationale"
                rows="3"
                placeholder="Why this approach over alternatives — what got rejected and why"
                class="input @error('rationale') has-error @enderror"
            >{{ old('rationale', $project->rationale) }}</textarea>
            @error('rationale')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Timeline with adaptive precision UX --}}
    <div class="space-y-5">
        <h2 class="section-heading">Timeline</h2>

        <div>
            <label for="date_precision" class="field-label">Date precision</label>
            <select
                id="date_precision"
                name="date_precision"
                required
                class="input @error('date_precision') has-error @enderror"
            >
                <option value="">Choose one…</option>
                <option value="day" @selected($currentPrecision === 'day')>Specific date</option>
                <option value="month" @selected($currentPrecision === 'month')>Month and year</option>
                <option value="quarter" @selected($currentPrecision === 'quarter')>Quarterly</option>
                <option value="year" @selected($currentPrecision === 'year')>Year only</option>
            </select>
            @error('date_precision')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <p class="field-help">
                How precisely you remember when this happened. Pick precision first, then enter dates.
            </p>
        </div>

        {{-- Date input groups. All four are rendered into the DOM but only
             the one matching the selected precision is visible. The
             matching pair is enabled (so its values are submitted) and
             the others are disabled (so their stale values are stripped
             from submission by the browser). --}}
        @php
            $startDay = $extractDay($project->start_date);
            $startMonth = $extractMonth($project->start_date);
            $startYear = $extractYear($project->start_date);
            $startQuarter = $extractQuarter($project->start_date);
            $endDay = $extractDay($project->end_date);
            $endMonth = $extractMonth($project->end_date);
            $endYear = $extractYear($project->end_date);
            $endQuarter = $extractQuarter($project->end_date);
        @endphp

        <div id="date_inputs_day" data-precision="day" @class(['hidden' => $currentPrecision !== 'day'])>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="start_date_day" class="field-label">Start date</label>
                    <input
                        type="date"
                        id="start_date_day"
                        name="start_date_day"
                        value="{{ old('start_date_day', $startDay) }}"
                        class="input"
                        @disabled($currentPrecision !== 'day')
                    >
                </div>
                <div>
                    <label for="end_date_day" class="field-label">
                        End date
                        <span class="field-label-hint">(blank if ongoing)</span>
                    </label>
                    <input
                        type="date"
                        id="end_date_day"
                        name="end_date_day"
                        value="{{ old('end_date_day', $endDay) }}"
                        class="input"
                        @disabled($currentPrecision !== 'day')
                    >
                </div>
            </div>
        </div>

        <div id="date_inputs_month" data-precision="month" @class(['hidden' => $currentPrecision !== 'month'])>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="start_date_month" class="field-label">Start month</label>
                    <input
                        type="month"
                        id="start_date_month"
                        name="start_date_month"
                        value="{{ old('start_date_month', $startMonth) }}"
                        class="input"
                        @disabled($currentPrecision !== 'month')
                    >
                </div>
                <div>
                    <label for="end_date_month" class="field-label">
                        End month
                        <span class="field-label-hint">(blank if ongoing)</span>
                    </label>
                    <input
                        type="month"
                        id="end_date_month"
                        name="end_date_month"
                        value="{{ old('end_date_month', $endMonth) }}"
                        class="input"
                        @disabled($currentPrecision !== 'month')
                    >
                </div>
            </div>
        </div>

        <div id="date_inputs_quarter" data-precision="quarter" @class(['hidden' => $currentPrecision !== 'quarter'])>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="field-label">Start quarter</label>
                    <div class="flex gap-3">
                        <select
                            name="start_date_quarter"
                            class="input"
                            @disabled($currentPrecision !== 'quarter')
                        >
                            <option value="">Quarter…</option>
                            @for ($q = 1; $q <= 4; $q++)
                                <option value="{{ $q }}" @selected(old('start_date_quarter', $startQuarter) == $q)>Q{{ $q }}</option>
                            @endfor
                        </select>
                        <input
                            type="text"
                            inputmode="numeric"
                            name="start_date_year"
                            value="{{ old('start_date_year', $currentPrecision === 'quarter' ? $startYear : '') }}"
                            placeholder="Year"
                            class="input"
                            @disabled($currentPrecision !== 'quarter')
                        >
                    </div>
                </div>
                <div>
                    <label class="field-label">
                        End quarter
                        <span class="field-label-hint">(blank if ongoing)</span>
                    </label>
                    <div class="flex gap-3">
                        <select
                            name="end_date_quarter"
                            class="input"
                            @disabled($currentPrecision !== 'quarter')
                        >
                            <option value="">Quarter…</option>
                            @for ($q = 1; $q <= 4; $q++)
                                <option value="{{ $q }}" @selected(old('end_date_quarter', $endQuarter) == $q)>Q{{ $q }}</option>
                            @endfor
                        </select>
                        <input
                            type="text"
                            inputmode="numeric"
                            name="end_date_year"
                            value="{{ old('end_date_year', $currentPrecision === 'quarter' ? $endYear : '') }}"
                            placeholder="Year"
                            class="input"
                            @disabled($currentPrecision !== 'quarter')
                        >
                    </div>
                </div>
            </div>
        </div>

        <div id="date_inputs_year" data-precision="year" @class(['hidden' => $currentPrecision !== 'year'])>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="start_date_year" class="field-label">Start year</label>
                    <input
                        type="text"
                        inputmode="numeric"
                        id="start_date_year"
                        name="start_date_year"
                        value="{{ old('start_date_year', $currentPrecision === 'year' ? $startYear : '') }}"
                        placeholder="2023"
                        class="input"
                        @disabled($currentPrecision !== 'year')
                    >
                </div>
                <div>
                    <label for="end_date_year" class="field-label">
                        End year
                        <span class="field-label-hint">(blank if ongoing)</span>
                    </label>
                    <input
                        type="text"
                        inputmode="numeric"
                        id="end_date_year"
                        name="end_date_year"
                        value="{{ old('end_date_year', $currentPrecision === 'year' ? $endYear : '') }}"
                        placeholder="2024"
                        class="input"
                        @disabled($currentPrecision !== 'year')
                    >
                </div>
            </div>
        </div>

        @error('start_date')
            <p class="field-error">{{ $message }}</p>
        @enderror
        @error('end_date')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Status & details --}}
    <div class="space-y-5">
        <h2 class="section-heading">Status &amp; details (optional)</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label for="status" class="field-label">Status</label>
                <select
                    id="status"
                    name="status"
                    class="input @error('status') has-error @enderror"
                >
                    <option value="">Choose one…</option>
                    @foreach ($statuses as $status)
                        <option
                            value="{{ $status }}"
                            @selected(old('status', $project->status) === $status)
                        >
                            {{ ucfirst($status) }}
                        </option>
                    @endforeach
                </select>
                @error('status')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="team_size" class="field-label">Team size</label>
                <input
                    type="text"
                    inputmode="numeric"
                    id="team_size"
                    name="team_size"
                    value="{{ old('team_size', $project->team_size) }}"
                    placeholder="3"
                    class="input @error('team_size') has-error @enderror"
                >
                @error('team_size')
                    <p class="field-error">{{ $message }}</p>
                @enderror
                <p class="field-help">
                    People on this specific project. May differ from your overall team size.
                </p>
            </div>
        </div>

        <div>
            <label for="contribution_type" class="field-label">Contribution type</label>
            <input
                type="text"
                id="contribution_type"
                name="contribution_type"
                value="{{ old('contribution_type', $project->contribution_type) }}"
                placeholder="feature_development, refactor, mentorship"
                class="input @error('contribution_type') has-error @enderror"
            >
            @error('contribution_type')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <p class="field-help">
                Free-form list of what kinds of work you did on this project. Comma-separated.
            </p>
        </div>
    </div>

    {{-- Notes --}}
    <div>
        <label for="user_notes" class="field-label">
            Private notes
            <span class="field-label-hint">(optional, never leaves your catalog)</span>
        </label>
        <textarea
            id="user_notes"
            name="user_notes"
            rows="4"
            placeholder="Anything that doesn't fit elsewhere — backstory, your impressions, things to remember"
            class="input @error('user_notes') has-error @enderror"
        >{{ old('user_notes', $project->user_notes) }}</textarea>
        @error('user_notes')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>
</div>

<script>
    (function () {
        // Public name toggle: show or hide the public_name input based
        // on the checkbox state. When hiding, we don't clear the value
        // — the user might toggle back. The server-side normalize step
        // will clean up if the wrapper is hidden but a value was retained
        // through old() repopulation; in practice this is fine.
        const publicToggle = document.getElementById('has_public_name_toggle');
        const publicWrapper = document.getElementById('public_name_wrapper');
        if (publicToggle && publicWrapper) {
            publicToggle.addEventListener('change', () => {
                publicWrapper.classList.toggle('hidden', ! publicToggle.checked);
            });
        }

        // Date precision: show only the input group matching the selected
        // precision, disable the others so their values aren't submitted.
        const precisionSelect = document.getElementById('date_precision');
        const dateGroups = document.querySelectorAll('[data-precision]');

        function updateDateInputs() {
            const selected = precisionSelect.value;
            dateGroups.forEach((group) => {
                const matches = group.dataset.precision === selected;
                group.classList.toggle('hidden', ! matches);
                // Disable inputs in non-matching groups so their values
                // aren't submitted alongside the active group's values.
                group.querySelectorAll('input, select').forEach((el) => {
                    el.disabled = ! matches;
                });
            });
        }

        if (precisionSelect) {
            precisionSelect.addEventListener('change', updateDateInputs);
        }
    })();
</script>