<?php

namespace App\Http\Controllers;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Per-source-document draft review queue. Walks the user through
 * pending drafts one at a time in a fixed type order:
 *
 *   organization → position → project → accomplishment
 *
 * The type order matches the dependency structure of the data —
 * positions reference organizations, projects reference positions,
 * accomplishments reference projects or positions. Reviewing in
 * order means by the time the user confirms an accomplishment, its
 * supporting org/position/project drafts have already been resolved.
 *
 * Confirm/reject/merge actions are added in subsequent mini-slices.
 * This slice renders read-only and provides prev/next navigation
 * between drafts in the queue.
 */
class DraftReviewController extends Controller
{
    /** SQL ordering across record types. */
    private const RECORD_TYPE_ORDER = [
        'organization' => 1,
        'position' => 2,
        'project' => 3,
        'accomplishment' => 4,
    ];

    /**
     * Entry point. Redirect to the first pending draft for this
     * document. If no pending drafts exist, send the user back to
     * the show page with a status message.
     */
    public function index(SourceDocument $sourceDocument): RedirectResponse
    {
        $first = $this->pendingDraftsQuery($sourceDocument)->first();

        if (! $first) {
            return redirect()
                ->route('source-documents.show', $sourceDocument)
                ->with('status', 'No drafts pending review.');
        }

        return redirect()->route('source-documents.review.show', [
            'sourceDocument' => $sourceDocument,
            'draft' => $first->id,
        ]);
    }

    /**
     * Display a single draft with progress bar and prev/next links.
     * The draft must belong to the source document — Laravel's route
     * model binding doesn't enforce that on its own.
     */
    public function show(SourceDocument $sourceDocument, ExtractedRecord $draft): View|RedirectResponse
    {
        if ($draft->source_document_id !== $sourceDocument->id) {
            abort(404);
        }

        // If this draft has already been reviewed (confirmed/rejected/merged),
        // surface it but the show page is the better destination since
        // there's nothing more to do here. The user might have clicked
        // an old browser-back link.
        if (! $draft->isPending()) {
            return redirect()
                ->route('source-documents.show', $sourceDocument)
                ->with('status', 'That draft has already been reviewed.');
        }

        // Load all pending drafts in queue order so we can compute
        // position and prev/next neighbors. At ~50 drafts max per
        // document this is fine; if it ever grows we can paginate.
        $queue = $this->pendingDraftsQuery($sourceDocument)->get();

        $position = $queue->search(fn ($d) => $d->id === $draft->id);
        if ($position === false) {
            // Shouldn't happen — the draft is pending and we're walking
            // the pending queue — but guard against it.
            abort(404);
        }

        $prev = $position > 0 ? $queue[$position - 1] : null;
        $next = $position < $queue->count() - 1 ? $queue[$position + 1] : null;

        return view('draft-reviews.show', [
            'sourceDocument' => $sourceDocument,
            'draft' => $draft,
            'position' => $position + 1,
            'total' => $queue->count(),
            'prev' => $prev,
            'next' => $next,
        ]);
    }

    /**
     * Pending drafts for a source document, ordered by record type
     * (orgs first, accomplishments last), then by id (creation order)
     * within each type.
     */
    private function pendingDraftsQuery(SourceDocument $sourceDocument)
    {
        // Build a CASE expression for type ordering. Works on both
        // MySQL (production) and SQLite (tests).
        $cases = collect(self::RECORD_TYPE_ORDER)
            ->map(fn ($order, $type) => "WHEN record_type = '{$type}' THEN {$order}")
            ->implode(' ');
        $orderExpression = "CASE {$cases} ELSE 99 END";

        return $sourceDocument->extractedRecords()
            ->where('status', 'pending')
            ->orderByRaw($orderExpression)
            ->orderBy('id');
    }
}