@php
    /**
     * Per-record partial for the dedicated link review page.
     *
     * Same card pattern as tag/person review with the addition of
     * editable fields (url, title, type, description, is_personal_appearance).
     * Fields save on blur/change via the update endpoint.
     */

    $linkPayload = $record->payload ?? [];
    $url = $linkPayload['url'] ?? '';
    $title = $linkPayload['title'] ?? '';
    $linkType = $linkPayload['type'] ?? '';
    $description = $linkPayload['description'] ?? '';
    $isPersonalAppearance = ! empty($linkPayload['is_personal_appearance']);

    $isApproved = $record->status === 'confirmed';
    $isRejected = $record->status === 'rejected';

    $borderClass = match (true) {
        $isApproved => 'tag-review-card tag-review-card--approved',
        $isRejected => 'tag-review-card tag-review-card--rejected',
        default => 'tag-review-card',
    };

    $linkTypes = \App\Models\Link::TYPES;
@endphp

<div
    class="{{ $borderClass }}"
    data-link-review-record
    data-status="{{ $record->status }}"
    data-accept-url="{{ route('source-documents.review.links.accept', ['sourceDocument' => $sourceDocument, 'record' => $record]) }}"
    data-reject-url="{{ route('source-documents.review.links.reject', ['sourceDocument' => $sourceDocument, 'record' => $record]) }}"
    data-update-url="{{ route('source-documents.review.links.update', ['sourceDocument' => $sourceDocument, 'record' => $record]) }}"
>
    {{-- URL display --}}
    <div class="mb-2">
        <a
            href="{{ $url ?: '#' }}"
            target="_blank"
            rel="noopener"
            class="link text-sm break-all"
            data-link-review-url-display
        >{{ $url ?: '(no url)' }}</a>
    </div>

    {{-- Mentions context --}}
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

    {{-- Editable fields --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3 mb-3">
        <div>
            <label class="metadata-label block mb-1 text-xs">URL</label>
            <input
                type="text"
                class="input text-sm"
                value="{{ $url }}"
                data-field="url"
            >
        </div>
        <div>
            <label class="metadata-label block mb-1 text-xs">Title</label>
            <input
                type="text"
                class="input text-sm"
                value="{{ $title }}"
                placeholder="Optional title"
                data-field="title"
            >
        </div>
        <div>
            <label class="metadata-label block mb-1 text-xs">Type</label>
            <select class="input text-sm" data-field="type">
                <option value="">—</option>
                @foreach ($linkTypes as $lt)
                    <option value="{{ $lt }}" @if ($linkType === $lt) selected @endif>
                        {{ str_replace('_', ' ', $lt) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <label class="flex items-center gap-2 text-sm cursor-pointer pb-2">
                <input
                    type="checkbox"
                    @if ($isPersonalAppearance) checked @endif
                    data-field="is_personal_appearance"
                    class="accent-current"
                    style="accent-color: var(--color-accent);"
                >
                <span>Personal appearance</span>
            </label>
        </div>
        <div class="sm:col-span-2">
            <label class="metadata-label block mb-1 text-xs">Description</label>
            <textarea
                class="input text-sm"
                rows="2"
                placeholder="Optional description"
                data-field="description"
            >{{ $description }}</textarea>
        </div>
    </div>

    {{-- Action buttons + status badges --}}
    <div class="flex items-center gap-2">
        <button type="button" data-action="accept" class="btn-primary text-sm">
            Accept
        </button>
        <button type="button" data-action="reject" class="btn-destructive text-sm">
            Reject
        </button>

        <span
            class="status-badge status-badge--review-approved ml-2"
            data-link-review-status-badge
            data-status-badge="confirmed"
            @if ($record->status !== 'confirmed') hidden @endif
        >
            Accepted
        </span>

        <span
            class="status-badge status-badge-rejected ml-2"
            data-link-review-status-badge
            data-status-badge="rejected"
            @if ($record->status !== 'rejected') hidden @endif
        >
            Rejected
        </span>
    </div>

    <p
        class="mt-2 text-sm"
        style="color: var(--color-error);"
        data-link-review-error
        hidden
    ></p>
</div>