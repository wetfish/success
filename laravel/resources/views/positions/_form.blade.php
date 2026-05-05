@csrf

<input type="hidden" name="organization_id" value="{{ $organization->id }}">

<div class="space-y-8">
    {{-- Required basics --}}
    <div class="space-y-5">
        <div>
            <label for="title" class="field-label">Title</label>
            <input
                type="text"
                id="title"
                name="title"
                value="{{ old('title', $position->title) }}"
                required
                autofocus
                placeholder="Senior Software Engineer"
                class="input @error('title') has-error @enderror"
            >
            @error('title')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label for="employment_type" class="field-label">Employment type</label>
                <select
                    id="employment_type"
                    name="employment_type"
                    required
                    class="input @error('employment_type') has-error @enderror"
                >
                    <option value="">Choose one…</option>
                    @foreach ($employmentTypes as $type)
                        <option
                            value="{{ $type }}"
                            @selected(old('employment_type', $position->employment_type) === $type)
                        >
                            {{ ucfirst(str_replace('_', ' ', $type)) }}
                        </option>
                    @endforeach
                </select>
                @error('employment_type')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="location_arrangement" class="field-label">Location arrangement</label>
                <select
                    id="location_arrangement"
                    name="location_arrangement"
                    required
                    class="input @error('location_arrangement') has-error @enderror"
                >
                    <option value="">Choose one…</option>
                    @foreach ($locationArrangements as $arrangement)
                        <option
                            value="{{ $arrangement }}"
                            @selected(old('location_arrangement', $position->location_arrangement) === $arrangement)
                        >
                            {{ ucfirst(str_replace('_', ' ', $arrangement)) }}
                        </option>
                    @endforeach
                </select>
                @error('location_arrangement')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label for="start_date" class="field-label">Start date</label>
                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="{{ old('start_date', $position->start_date?->format('Y-m-d')) }}"
                    required
                    class="input @error('start_date') has-error @enderror"
                >
                @error('start_date')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="end_date" class="field-label">
                    End date
                    <span class="field-label-hint">(leave blank if current)</span>
                </label>
                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="{{ old('end_date', $position->end_date?->format('Y-m-d')) }}"
                    class="input @error('end_date') has-error @enderror"
                >
                @error('end_date')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    {{-- Mandate — given prominent placement because it captures the
         "what you were hired to do" context that doesn't emerge from
         project data and is architecturally important. --}}
    <div>
        <label for="mandate" class="field-label">
            Mandate
            <span class="field-label-hint">(optional)</span>
        </label>
        <textarea
            id="mandate"
            name="mandate"
            rows="3"
            placeholder="What you were hired to do — your charter, scope, or the problem you were brought in to solve"
            class="input @error('mandate') has-error @enderror"
        >{{ old('mandate', $position->mandate) }}</textarea>
        @error('mandate')
            <p class="field-error">{{ $message }}</p>
        @enderror
        <p class="field-help">
            This captures top-down information about the role that doesn't naturally emerge from individual projects or accomplishments.
        </p>
    </div>

    {{-- Location detail --}}
    <div class="space-y-5">
        <h2 class="section-heading">Location (optional)</h2>

        <div>
            <label for="location_text" class="field-label">Location detail</label>
            <input
                type="text"
                id="location_text"
                name="location_text"
                value="{{ old('location_text', $position->location_text) }}"
                placeholder="NYC office, Berlin (3 days/week), Distributed across timezones"
                class="input @error('location_text') has-error @enderror"
            >
            @error('location_text')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <p class="field-help">
                Any free-text annotation that adds nuance beyond the arrangement type above.
            </p>
        </div>
    </div>

    {{-- Team --}}
    <div class="space-y-5">
        <h2 class="section-heading">Team (optional)</h2>

        <div>
            <label for="team_name" class="field-label">Team name</label>
            <input
                type="text"
                id="team_name"
                name="team_name"
                value="{{ old('team_name', $position->team_name) }}"
                placeholder="Platform, Growth, Terminal Web"
                class="input @error('team_name') has-error @enderror"
            >
            @error('team_name')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label for="team_size_immediate" class="field-label">Immediate team size</label>
                <input
                    type="text"
                    inputmode="numeric"
                    id="team_size_immediate"
                    name="team_size_immediate"
                    value="{{ old('team_size_immediate', $position->team_size_immediate) }}"
                    placeholder="5"
                    class="input @error('team_size_immediate') has-error @enderror"
                >
                @error('team_size_immediate')
                    <p class="field-error">{{ $message }}</p>
                @enderror
                <p class="field-help">
                    People you worked with day-to-day.
                </p>
            </div>

            <div>
                <label for="team_size_extended" class="field-label">Extended team size</label>
                <input
                    type="text"
                    inputmode="numeric"
                    id="team_size_extended"
                    name="team_size_extended"
                    value="{{ old('team_size_extended', $position->team_size_extended) }}"
                    placeholder="30"
                    class="input @error('team_size_extended') has-error @enderror"
                >
                @error('team_size_extended')
                    <p class="field-error">{{ $message }}</p>
                @enderror
                <p class="field-help">
                    Broader org you were part of.
                </p>
            </div>
        </div>
    </div>

    {{-- End-of-tenure context --}}
    <div class="space-y-5">
        <h2 class="section-heading">End of tenure (optional)</h2>

        <div>
            <label for="reason_for_leaving" class="field-label">Reason for leaving</label>
            <select
                id="reason_for_leaving"
                name="reason_for_leaving"
                class="input @error('reason_for_leaving') has-error @enderror"
            >
                <option value="">Don't know yet</option>
                @foreach ($reasonsForLeaving as $reason)
                    <option
                        value="{{ $reason }}"
                        @selected(old('reason_for_leaving', $position->reason_for_leaving) === $reason)
                    >
                        {{ ucfirst(str_replace('_', ' ', $reason)) }}
                    </option>
                @endforeach
            </select>
            @error('reason_for_leaving')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- The notes field is hidden by default and only revealed when
             a non-empty, non-still_employed reason is selected. The
             initial visibility is determined server-side based on the
             current value, so the field renders correctly on edit. --}}
        @php
            $currentReason = old('reason_for_leaving', $position->reason_for_leaving);
            $notesVisible = $currentReason && $currentReason !== 'still_employed';
        @endphp
        <div
            id="reason_for_leaving_notes_wrapper"
            @class(['hidden' => ! $notesVisible])
        >
            <label for="reason_for_leaving_notes" class="field-label">
                Context
                <span class="field-label-hint">(private — never goes on a resume)</span>
            </label>
            <textarea
                id="reason_for_leaving_notes"
                name="reason_for_leaving_notes"
                rows="3"
                placeholder="What actually happened, in your own words"
                class="input @error('reason_for_leaving_notes') has-error @enderror"
            >{{ old('reason_for_leaving_notes', $position->reason_for_leaving_notes) }}</textarea>
            @error('reason_for_leaving_notes')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- General notes --}}
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
        >{{ old('user_notes', $position->user_notes) }}</textarea>
        @error('user_notes')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>
</div>

{{-- Inline JS to toggle the visibility of the reason_for_leaving_notes
     field based on the selected reason. Kept inline since this is the
     only interactive element on the page — extracting to a separate JS
     file would be overkill. --}}
<script>
    (function () {
        const select = document.getElementById('reason_for_leaving');
        const wrapper = document.getElementById('reason_for_leaving_notes_wrapper');

        if (! select || ! wrapper) {
            return;
        }

        function updateVisibility() {
            const value = select.value;
            const shouldShow = value && value !== 'still_employed';
            wrapper.classList.toggle('hidden', ! shouldShow);
        }

        select.addEventListener('change', updateVisibility);
    })();
</script>