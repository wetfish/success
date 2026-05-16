@php
    /**
     * Per-record partial for the person review page.
     *
     * Same card shape as tag-reviews/_record.blade.php minus the alias
     * action button, alias picker slot, and merged status badge. Two
     * actions (Accept / Reject) instead of three. The card border tint,
     * status pill rendering, and data-attribute contract are identical.
     *
     * Mentions include the collaborator role where available, so the
     * user sees "Mentioned as Manager on Acme (organization)" rather
     * than just "Mentioned on: Acme (organization)".
     */

    $matchedPersonName = null;
    if ($record->status === 'confirmed' && $record->match_record_id) {
        $matchedPersonName = optional(\App\Models\Person::find($record->match_record_id))->name;
    }

    $extractedName = $record->payload['extracted_name'] ?? '(unknown)';

    $isPending = $record->status === 'pending';
    $isApproved = $record->status === 'confirmed';
    $isRejected = $record->status === 'rejected';

    $borderClass = match (true) {
        $isApproved => 'tag-review-card tag-review-card--approved',
        $isRejected => 'tag-review-card tag-review-card--rejected',
        default => 'tag-review-card',
    };
@endphp

<div
    class="{{ $borderClass }}"
    data-people-review-record
    data-status="{{ $record->status }}"
    data-accept-url="{{ route('source-documents.review.people.accept', ['sourceDocument' => $sourceDocument, 'record' => $record]) }}"
    data-reject-url="{{ route('source-documents.review.people.reject', ['sourceDocument' => $sourceDocument, 'record' => $record]) }}"
>
    <div class="flex items-baseline gap-2 mb-2">
        <h3 class="text-base font-medium">{{ $extractedName }}</h3>
    </div>

    @if (! empty($mentions))
        @php
            $displayedMentions = array_slice($mentions, 0, 3);
            $extraCount = max(0, count($mentions) - 3);
        @endphp
        <p class="text-sm mb-3" style="color: var(--color-text-muted);">
            Mentioned on:
            @foreach ($displayedMentions as $idx => $mention)
                <span>{{ $mention['name'] }}</span>
                @if (! empty($mention['role']))
                    <span style="color: var(--color-text-muted); opacity: 0.7;">as {{ $mention['role'] }}</span>
                @endif
                <span style="color: var(--color-text-muted); opacity: 0.7;">({{ $mention['type'] }})</span>{{ $idx < count($displayedMentions) - 1 ? ',' : '' }}
            @endforeach
            @if ($extraCount > 0)
                <span>+ {{ $extraCount }} more</span>
            @endif
        </p>
    @endif

    <div class="flex gap-2" data-people-review-actions>
        <button type="button" data-action="accept" class="btn-primary text-sm">
            Accept
        </button>
        <button type="button" data-action="reject" class="btn-destructive text-sm">
            Reject
        </button>
    </div>

    <div class="mt-1">
        <span
            class="status-badge status-badge--review-approved"
            data-people-review-status-badge
            data-status-badge="confirmed"
            @if ($record->status !== 'confirmed') hidden @endif
        >
            Accepted as
            <span data-people-review-accept-target class="ml-1">{{ $matchedPersonName ?? $extractedName }}</span>
        </span>

        <span
            class="status-badge status-badge-rejected"
            data-people-review-status-badge
            data-status-badge="rejected"
            @if ($record->status !== 'rejected') hidden @endif
        >
            Rejected
        </span>
    </div>

    <p
        class="mt-2 text-sm"
        style="color: var(--color-error);"
        data-people-review-error
        hidden
    ></p>
</div>