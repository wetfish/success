{{-- Selection card with context-appropriate actions.
     AI-suggested entries get Include/Exclude toggles (preserving
     the decision we paid for). User-added entries get a Remove
     button that deletes the row entirely.

     Variables:
       $draft     — the ResumeDraft
       $selection — the ResumeSelection record
       $title     — display title for this entry
       $subtitle  — optional context line
       $typeBadge — short type label ("Position", "Project", "Skill", etc.)
       $url       — optional link to the source record's show/edit page
--}}
@php
    $isUserAdded = $selection->ai_reasoning === null;
    $url = $url ?? null;
    $stateClass = $isUserAdded
        ? 'selection-card--included'
        : ($selection->selected ? 'selection-card--included' : 'selection-card--excluded');
@endphp

<div
    class="selection-card {{ $stateClass }}"
    data-selection-card
    data-selection-id="{{ $selection->id }}"
    data-selected="{{ $selection->selected ? 'true' : 'false' }}"
    data-toggle-url="{{ route('resume-drafts.toggle', [$draft, $selection]) }}"
    data-note-url="{{ route('resume-drafts.update-note', [$draft, $selection]) }}"
    @if ($isUserAdded)
        data-remove-url="{{ route('resume-drafts.remove-selection', [$draft, $selection]) }}"
        data-user-added
    @endif
>
    <div class="flex items-start justify-between gap-3 mb-2">
        <div class="min-w-0">
            <div class="flex items-baseline gap-2 flex-wrap">
                @if ($url)
                    <a href="{{ $url }}" class="text-base font-medium link-emphasis" data-selection-title>{{ $title }}</a>
                @else
                    <h3 class="text-base font-medium" data-selection-title>{{ $title }}</h3>
                @endif
                <span
                    class="text-xs px-1.5 py-0.5 rounded shrink-0"
                    style="background: var(--color-surface-input-border); color: var(--color-text-secondary);"
                >
                    {{ $typeBadge }}
                </span>
            </div>
            @if ($subtitle)
                <p class="text-sm mt-0.5" style="color: var(--color-text-secondary);">
                    {{ $subtitle }}
                </p>
            @endif
        </div>

        {{-- Status badges --}}
        <div class="shrink-0">
            @if ($isUserAdded)
                <span
                    class="text-xs px-1.5 py-0.5 rounded"
                    style="background: var(--color-surface-input-border); color: var(--color-text-secondary);"
                >
                    Added by you
                </span>
            @else
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
            @endif
        </div>
    </div>

    {{-- AI reasoning — only on AI-suggested entries --}}
    @if ($selection->ai_reasoning)
        <p class="text-sm mb-3 leading-relaxed" style="color: var(--color-text-muted);">
            <span class="font-medium" style="color: var(--color-text-secondary);">AI:</span>
            {{ $selection->ai_reasoning }}
        </p>
    @endif

    {{-- User relevance note — editable --}}
    @if ($draft->isSelecting())
        <div class="mb-3 relative" style="padding-bottom: 1.25rem;" data-note-editor>
            <label class="text-xs font-medium mb-1 block" style="color: var(--color-text-secondary);">
                Your note — how does this address the requirement?
            </label>
            <textarea
                class="input text-sm"
                rows="2"
                style="overflow: hidden; resize: none;"
                placeholder="Describe how this experience is relevant to this specific requirement…"
                data-note-input
            >{{ $selection->user_relevance_note }}</textarea>
            <span
                class="text-xs absolute bottom-0 left-0"
                style="color: var(--color-text-muted);"
                data-note-status
            ></span>
        </div>
    @elseif ($selection->user_relevance_note)
        <p class="text-sm mb-3 leading-relaxed" style="color: var(--color-text-secondary);">
            <span class="font-medium">Your note:</span>
            {{ $selection->user_relevance_note }}
        </p>
    @endif

    {{-- Action buttons --}}
    @if ($draft->isSelecting())
        <div class="flex gap-2" data-selection-actions>
            @if ($isUserAdded)
                <button
                    type="button"
                    data-action="remove"
                    class="btn-destructive text-sm"
                >
                    Remove
                </button>
            @else
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
            @endif
        </div>
    @endif

    {{-- Error slot --}}
    <p
        class="mt-2 text-sm"
        style="color: var(--color-error);"
        data-selection-error
        hidden
    ></p>
</div>