{{-- A single selection card with explicit Include / Exclude buttons.
     Same pattern as the tag review cards: always-visible action buttons,
     card border tint changes on decision, status badge shows current
     state. The user can change their mind by clicking the other button.

     Variables:
       $draft     — the ResumeDraft (for building toggle URLs)
       $selection — the ResumeSelection record
       $title     — display title for this entry
       $subtitle  — optional context line
       $indent    — nesting depth (0, 1, or 2)
--}}
@php
    $indentClass = match ($indent) {
        1 => 'ml-6',
        2 => 'ml-12',
        default => '',
    };

    $stateClass = $selection->selected
        ? 'selection-card--included'
        : 'selection-card--excluded';
@endphp

<div
    class="selection-card {{ $stateClass }} {{ $indentClass }}"
    data-selection-card
    data-selection-id="{{ $selection->id }}"
    data-selected="{{ $selection->selected ? 'true' : 'false' }}"
    data-toggle-url="{{ route('resume-drafts.toggle', [$draft, $selection]) }}"
>
    <div class="mb-2">
        <h3 class="text-base font-medium" data-selection-title>{{ $title }}</h3>
        @if ($subtitle)
            <p class="text-sm mt-0.5" style="color: var(--color-text-secondary);">
                {{ $subtitle }}
            </p>
        @endif
    </div>

    {{-- AI reasoning --}}
    @if ($selection->ai_reasoning)
        <p class="text-sm mb-3 leading-relaxed" style="color: var(--color-text-muted);">
            {{ $selection->ai_reasoning }}
        </p>
    @endif

    {{-- Action buttons — always visible so the user can change their mind --}}
    @if ($draft->isSelecting())
        <div class="flex gap-2" data-selection-actions>
            <button
                type="button"
                data-action="include"
                class="btn-primary text-sm"
                @if ($selection->selected) disabled @endif
            >
                Include
            </button>
            <button
                type="button"
                data-action="exclude"
                class="btn-destructive text-sm"
                @if (! $selection->selected) disabled @endif
            >
                Exclude
            </button>
        </div>
    @endif

    {{-- Status badges — toggled by JS --}}
    <div class="mt-1">
        <span
            class="status-badge status-badge--review-approved"
            data-selection-badge="included"
            @if (! $selection->selected) hidden @endif
        >
            Included
        </span>
        <span
            class="status-badge status-badge-rejected"
            data-selection-badge="excluded"
            @if ($selection->selected) hidden @endif
        >
            Excluded
        </span>
    </div>

    {{-- Error slot --}}
    <p
        class="mt-2 text-sm"
        style="color: var(--color-error);"
        data-selection-error
        hidden
    ></p>
</div>