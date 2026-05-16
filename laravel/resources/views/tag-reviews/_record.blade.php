@php
    /**
     * Per-record partial for the tag review page.
     *
     * Renders one tag review record's card. Server-side, the card is
     * pre-styled to match its persisted status (pink border + approved
     * pill for confirmed/merged, muted border + rejected pill for
     * rejected, default border + visible action buttons for pending).
     *
     * The JS handles in-page transitions by toggling visibility on the
     * three status-badge elements (all rendered upfront) and on the
     * action buttons row. Each card carries data-* attributes for its
     * action endpoints so the JS doesn't have to construct URLs.
     */

    // Resolved catalog tag for confirmed/merged records, used to render
    // the pill text "Accepted as X" / "Merged into Y". Eager-loaded if
    // the controller passed records with .matchedTag relation; falls
    // back to a runtime lookup otherwise. Null for pending/rejected.
    $matchedTagName = null;
    if (in_array($record->status, ['confirmed', 'merged'], true) && $record->match_record_id) {
        $matchedTagName = optional(\App\Models\Tag::find($record->match_record_id))->name;
    }

    $extractedName = $record->payload['extracted_name'] ?? '(unknown)';
    $aiCategory = $record->payload['category'] ?? null;

    // Visual state classes. The card border + the right status-pill
    // visibility branch off the persisted status. Pending shows action
    // buttons; everything else shows a pill.
    $isPending = $record->status === 'pending';
    $isApproved = in_array($record->status, ['confirmed', 'merged'], true);
    $isRejected = $record->status === 'rejected';

    // Card border tint — pink for approved decisions, muted-grey for
    // rejected, default surface-border for pending. The classes apply
    // a CSS variable override for border-color only — the card
    // background stays the standard semi-transparent surface.
    $borderClass = match (true) {
        $isApproved => 'tag-review-card tag-review-card--approved',
        $isRejected => 'tag-review-card tag-review-card--rejected',
        default => 'tag-review-card',
    };
@endphp

<div
    class="{{ $borderClass }}"
    data-tag-review-record
    data-status="{{ $record->status }}"
    data-accept-url="{{ route('source-documents.review.tags.accept', ['sourceDocument' => $sourceDocument, 'record' => $record]) }}"
    data-reject-url="{{ route('source-documents.review.tags.reject', ['sourceDocument' => $sourceDocument, 'record' => $record]) }}"
    data-alias-url="{{ route('source-documents.review.tags.alias', ['sourceDocument' => $sourceDocument, 'record' => $record]) }}"
>
    {{-- Top row: extracted name + AI-emitted category hint --}}
    <div class="flex items-baseline gap-2 mb-2">
        <h3 class="text-base font-medium">{{ $extractedName }}</h3>
        @if ($aiCategory)
            <span class="text-xs" style="color: var(--color-text-muted);">
                ({{ $aiCategory }})
            </span>
        @endif
    </div>

    {{-- Mentions context. Helps the user judge what's worth keeping
         — knowing "Postgres was mentioned on 3 things" matters more
         than the bare name. Up to 3 mentions are listed explicitly;
         beyond that we show "+ N more" to keep card height bounded. --}}
    @if (! empty($mentions))
        @php
            $displayedMentions = array_slice($mentions, 0, 3);
            $extraCount = max(0, count($mentions) - 3);
        @endphp
        <p class="text-sm mb-3" style="color: var(--color-text-muted);">
            Mentioned on:
            @foreach ($displayedMentions as $idx => $mention)
                <span>{{ $mention['name'] }}</span>
                <span style="color: var(--color-text-muted); opacity: 0.7;">({{ $mention['type'] }})</span>{{ $idx < count($displayedMentions) - 1 ? ',' : '' }}
            @endforeach
            @if ($extraCount > 0)
                <span>+ {{ $extraCount }} more</span>
            @endif
        </p>
    @endif

    {{-- Action button row. Always visible — even on already-decided
         records — so the user can change their mind by clicking a
         different action. Each transition reverts the previous one
         (controller's revertPriorDecision) before applying the new
         state. The card border and status pill are the visual cues
         for "what's the current decision." --}}
    <div class="flex gap-2" data-tag-review-actions>
        <button type="button" data-action="accept" class="btn-primary text-sm">
            Accept
        </button>
        <button type="button" data-action="alias" class="btn-secondary text-sm">
            Alias to…
        </button>
        <button type="button" data-action="reject" class="btn-destructive text-sm">
            Reject
        </button>
    </div>

    {{-- Status pills. All three render upfront with hidden=true on the
         ones that don't match the persisted status. The JS toggles the
         hidden attribute on each as decisions change. Pill text shows
         the resolved catalog tag name where applicable. --}}
    <div class="mt-1">
        <span
            class="status-badge status-badge--review-approved"
            data-tag-review-status-badge
            data-status-badge="confirmed"
            @if ($record->status !== 'confirmed') hidden @endif
        >
            Accepted as
            <span data-tag-review-accept-target class="ml-1">{{ $matchedTagName ?? $extractedName }}</span>
        </span>

        <span
            class="status-badge status-badge--review-approved"
            data-tag-review-status-badge
            data-status-badge="merged"
            @if ($record->status !== 'merged') hidden @endif
        >
            Merged into
            <span data-tag-review-merge-target class="ml-1">{{ $matchedTagName ?? '...' }}</span>
        </span>

        <span
            class="status-badge status-badge-rejected"
            data-tag-review-status-badge
            data-status-badge="rejected"
            @if ($record->status !== 'rejected') hidden @endif
        >
            Rejected
        </span>
    </div>

    {{-- Alias picker slot. Empty until the user clicks "Alias to..."
         on this record, at which point the shared alias picker JS
         instance mounts itself into this slot. The slot is always
         in the DOM so the JS has a stable target; the visibility is
         controlled by the picker itself. --}}
    <div class="mt-3" data-tag-review-alias-slot></div>

    {{-- Error slot. Hidden by default; the JS populates and unhides
         on action failures. Stays in the DOM so multiple error/recovery
         cycles work without DOM churn. --}}
    <p
        class="mt-2 text-sm"
        style="color: var(--color-error);"
        data-tag-review-error
        hidden
    ></p>
</div>