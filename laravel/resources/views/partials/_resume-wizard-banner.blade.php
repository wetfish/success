{{-- Contextual banner shown when the user is reviewing a document
     that originated from the resume wizard's per-requirement freeform
     text input. Reminds them what they were doing and where they'll
     return to when done.

     Expects $sourceDocument in scope (present in all review views). --}}
@if ($sourceDocument->origin === 'requirement_response' && $sourceDocument->requirement)
    @php
        $req = $sourceDocument->requirement;
        $returnDraft = \App\Models\ResumeDraft::where('job_listing_id', $req->job_listing_id)
            ->where('status', 'selecting')
            ->first();
    @endphp

    @if ($returnDraft)
        <div
            class="rounded-lg border px-4 py-3 mb-6 text-sm"
            style="border-color: var(--color-accent); background: rgb(217 70 163 / 0.06);"
        >
            <p>
                <span class="font-medium" style="color: var(--color-accent);">Resume wizard:</span>
                reviewing experience for
                <span class="font-medium">{{ $req->title }}</span>.
                You'll return to your application when this review is complete.
            </p>
        </div>
    @endif
@endif