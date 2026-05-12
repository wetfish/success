@php
    /**
     * Tag picker partial. Renders a multi-select control where the
     * user can attach tags to a parent entity.
     *
     * Required variable:
     *   $entity     — the parent model instance, which must have a
     *                 `tags()` morphToMany relationship and any
     *                 currently-attached tags loaded or loadable.
     *
     * Optional variables:
     *   $inputName  — name attribute for the hidden inputs that carry
     *                 the selected tag IDs. Defaults to 'tag_ids'.
     *                 Submitted as `name="tag_ids[]"` so Laravel
     *                 receives an array.
     *   $label      — visible label for the field. Defaults to 'Tags'.
     *
     * The partial server-renders the parent's currently-attached tags
     * as chips with hidden inputs already populated. The JS module
     * (tag-picker.js) auto-mounts on `[data-tag-picker]` and takes
     * over for autocomplete, add, and remove interactions.
     *
     * If JS fails to load, the user still sees their existing tags
     * and can submit the form without changes — graceful degradation.
     * Adding/removing tags requires JS.
     */

    $inputName = $inputName ?? 'tag_ids';
    $label = $label ?? 'Tags';
    // For unpersisted entities (create forms), there can't be any
    // tag attachments yet — skip the query that would scan for them
    // against a null taggable_id and yield nothing.
    $selectedTags = $entity->exists ? $entity->tags : collect();
@endphp

<div>
    <label class="field-label">{{ $label }}</label>

    <div
        class="tag-picker"
        data-tag-picker
        data-input-name="{{ $inputName }}"
        data-search-url="{{ route('tags.search') }}"
        data-manage-url="{{ route('tags.index') }}"
    >
        {{-- Selected tag chips. Each chip carries its own hidden input
             so the form submission includes the tag IDs natively. The
             JS module reads data-tag-id to track which tags are already
             selected and filters them out of autocomplete suggestions. --}}
        <div class="tag-picker-chips" data-tag-picker-chips>
            @foreach ($selectedTags as $selected)
                <span class="tag-picker-chip" data-tag-id="{{ $selected->id }}">
                    <span class="tag-picker-chip-label">{{ $selected->name }}</span>
                    <button
                        type="button"
                        class="tag-picker-chip-remove"
                        aria-label="Remove {{ $selected->name }}"
                        data-tag-picker-remove
                    >
                        {{-- × glyph drawn in SVG so the stroke
                             color follows currentColor. --}}
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <line x1="2" y1="2" x2="8" y2="8"/>
                            <line x1="8" y1="2" x2="2" y2="8"/>
                        </svg>
                    </button>
                    <input type="hidden" name="{{ $inputName }}[]" value="{{ $selected->id }}">
                </span>
            @endforeach
        </div>

        {{-- Input + dropdown. The input is a plain text field; the
             dropdown is a styled ul positioned absolutely below it.
             The wrapper is `position: relative` so the dropdown
             anchors to the input. --}}
        <div class="tag-picker-input-wrap">
            <input
                type="text"
                class="input tag-picker-input"
                placeholder="Type to search tags…"
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-expanded="false"
                aria-controls="tag-picker-dropdown-{{ $inputName }}"
                data-tag-picker-input
            >
            <ul
                id="tag-picker-dropdown-{{ $inputName }}"
                class="tag-picker-dropdown"
                role="listbox"
                hidden
                data-tag-picker-dropdown
            ></ul>
        </div>
    </div>

    <p class="field-help">
        Skills, technologies, domains — what this work involved.
        <a href="{{ route('tags.index') }}" class="link-emphasis" target="_blank" rel="noopener">
            Manage tags →
        </a>
    </p>
</div>