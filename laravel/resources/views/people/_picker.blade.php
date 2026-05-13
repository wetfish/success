@php
    /**
     * Person picker partial. Renders a multi-select control where
     * the user can attach collaborators (with role) to a parent
     * entity. Used on position, project, and accomplishment forms.
     *
     * Required variable:
     *   $entity     — the parent model instance, which must have a
     *                 `collaborators()` belongsToMany relationship
     *                 with a `role_on_*` pivot column.
     *
     * Optional variables:
     *   $inputName  — name attribute for the form fields. Defaults
     *                 to 'collaborators'. Each selected collaborator
     *                 contributes two fields: {inputName}[i][person_id]
     *                 and {inputName}[i][role]. The index i is a
     *                 monotonic counter, not necessarily sequential —
     *                 Laravel collects sparse arrays fine.
     *   $label      — visible label. Defaults to 'Collaborators'.
     *   $roleField  — the pivot column name on the parent's collaborators
     *                 relationship (e.g., 'role_on_position'). Required
     *                 when $entity->exists, because we need to read the
     *                 role from the pivot. If absent on a persisted
     *                 entity, the chip's role field renders empty.
     *
     * If JS fails to load, server-rendered chips remain visible and
     * the form submits unchanged — graceful degradation. Adding or
     * removing collaborators requires JS.
     */

    $inputName = $inputName ?? 'collaborators';
    $label = $label ?? 'Collaborators';
    $roleField = $roleField ?? null;

    // Skip the database query on unpersisted entities — there can't
    // be any collaborator attachments yet. Same defensive pattern as
    // the tag picker.
    $selectedCollaborators = $entity->exists ? $entity->collaborators : collect();

    // Common role values surfaced as datalist suggestions. Free text,
    // but nudges users toward consistency. The datalist ID derives
    // from $inputName so multiple pickers on the same page (rare but
    // possible) don't collide.
    $datalistId = 'person-picker-roles-' . $inputName;
    $roleSuggestions = ['Manager', 'Direct report', 'Peer', 'Mentor', 'Mentee', 'Client', 'Vendor'];
@endphp

<div>
    <label class="field-label">{{ $label }}</label>

    <div
        class="person-picker"
        data-person-picker
        data-input-name="{{ $inputName }}"
        data-search-url="{{ route('people.search') }}"
        data-role-datalist="{{ $datalistId }}"
    >
        {{-- Selected chips. Each chip carries a hidden person_id input
             (so the form knows who's selected), a visible role text
             input (so the user can label the relationship), and a
             remove button. The chip's index is server-rendered using
             $loop->index; JS uses a monotonic counter for new chips.

             Server-rendered chips use a "server" index prefix to avoid
             colliding with client-added chips, but since Laravel just
             collects whatever indices exist, the exact values don't
             matter — they just need to be unique within the form. --}}
        <div class="person-picker-chips" data-person-picker-chips>
            @foreach ($selectedCollaborators as $person)
                @php
                    $role = $roleField ? ($person->pivot->{$roleField} ?? '') : '';
                @endphp
                <div class="person-picker-chip" data-person-id="{{ $person->id }}">
                    <div class="person-picker-chip-name">{{ $person->name }}</div>
                    <input
                        type="text"
                        class="person-picker-chip-role input"
                        name="{{ $inputName }}[{{ $loop->index }}][role]"
                        value="{{ $role }}"
                        list="{{ $datalistId }}"
                        placeholder="Role…"
                        maxlength="255"
                    >
                    <button
                        type="button"
                        class="person-picker-chip-remove"
                        aria-label="Remove {{ $person->name }}"
                        data-person-picker-remove
                    >
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <line x1="2" y1="2" x2="8" y2="8"/>
                            <line x1="8" y1="2" x2="2" y2="8"/>
                        </svg>
                    </button>
                    <input type="hidden" name="{{ $inputName }}[{{ $loop->index }}][person_id]" value="{{ $person->id }}">
                </div>
            @endforeach
        </div>

        {{-- Search input and dropdown --}}
        <div class="person-picker-input-wrap">
            <input
                type="text"
                class="input person-picker-input"
                placeholder="Type a name to search…"
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-expanded="false"
                aria-controls="person-picker-dropdown-{{ $inputName }}"
                data-person-picker-input
            >
            <ul
                id="person-picker-dropdown-{{ $inputName }}"
                class="person-picker-dropdown"
                role="listbox"
                hidden
                data-person-picker-dropdown
            ></ul>
        </div>
    </div>

    <datalist id="{{ $datalistId }}">
        @foreach ($roleSuggestions as $suggestion)
            <option value="{{ $suggestion }}">
        @endforeach
    </datalist>

    <p class="field-help">
        People you worked with on this. Each can have a free-text role.
        <a href="{{ route('people.index') }}" class="link-emphasis" target="_blank" rel="noopener">
            Manage people →
        </a>
    </p>
</div>