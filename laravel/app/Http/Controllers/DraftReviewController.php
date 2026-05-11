<?php

namespace App\Http\Controllers;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Per-source-document draft review queue. Walks the user through
 * drafts one at a time in a fixed type order:
 *
 *   organization → position → project → accomplishment
 *
 * The type order matches the dependency structure of the data —
 * positions reference organizations, projects reference positions,
 * accomplishments reference projects or positions. Reviewing in
 * order means by the time the user confirms an accomplishment, its
 * supporting org/position/project drafts have already been resolved.
 *
 * All drafts are browsable regardless of status — pending, rejected,
 * confirmed, or merged. The review page surfaces each draft's status
 * and the relevant actions for that status (reject for pending,
 * restore for rejected, etc.). Nothing is hidden; the user can
 * always navigate to any draft the AI produced.
 *
 * Confirm and merge actions are added in subsequent mini-slices.
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
     * document. If no pending drafts exist (everything reviewed),
     * fall back to the first draft in the queue so the user can
     * still browse what they decided. If there are no drafts at all,
     * send the user back to the show page.
     */
    public function index(SourceDocument $sourceDocument): RedirectResponse
    {
        $first = $this->pendingDraftsQuery($sourceDocument)->first()
            ?? $this->allDraftsQuery($sourceDocument)->first();

        if (! $first) {
            return redirect()
                ->route('source-documents.show', $sourceDocument)
                ->with('status', 'No drafts to review.');
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
     *
     * Drafts in any status are browsable. Already-reviewed drafts
     * (rejected/confirmed/merged) show their status and the relevant
     * action (e.g., "Restore to pending" for rejected). Nothing is
     * hidden — the review page is the canonical view of all drafts
     * the AI produced, in any state.
     */
    public function show(SourceDocument $sourceDocument, ExtractedRecord $draft): View|RedirectResponse
    {
        if ($draft->source_document_id !== $sourceDocument->id) {
            abort(404);
        }

        // Load all drafts in queue order so we can compute position
        // and prev/next neighbors. At ~50 drafts max per document
        // this is fine; if it ever grows we can paginate.
        $queue = $this->allDraftsQuery($sourceDocument)->get();

        $position = $queue->search(fn ($d) => $d->id === $draft->id);
        if ($position === false) {
            abort(404);
        }

        $prev = $position > 0 ? $queue[$position - 1] : null;
        $next = $position < $queue->count() - 1 ? $queue[$position + 1] : null;

        // Counts for the progress bar. The bar visualizes review
        // progress (reviewed / total), separate from queue position
        // which is shown as a numeric label.
        $reviewedCount = $queue->filter(fn ($d) => $d->status !== 'pending')->count();
        $totalCount = $queue->count();

        // Count cascade dependents so the view can render the
        // confirmation modal copy ("will also reject N drafts").
        // Only meaningful when the draft is still pending; rejected
        // drafts can't be re-rejected, so dependents don't matter.
        $dependentCount = $draft->isPending()
            ? $draft->findDependents()->count()
            : 0;

        return view('draft-reviews.show', [
            'sourceDocument' => $sourceDocument,
            'draft' => $draft,
            'position' => $position + 1,
            'total' => $queue->count(),
            'reviewedCount' => $reviewedCount,
            'totalCount' => $totalCount,
            'prev' => $prev,
            'next' => $next,
            'dependentCount' => $dependentCount,
        ]);
    }

    /**
     * Reject a draft and cascade rejection to its dependent drafts.
     * Rejection is a soft state change — the draft row stays but its
     * status moves from 'pending' to 'rejected'. After rejection the
     * user is sent to the next pending draft in the queue, or back
     * to the source document if the queue is empty.
     *
     * All updates happen inside a transaction so a partial cascade
     * is impossible — either the whole tree rejects or nothing does.
     */
    public function reject(SourceDocument $sourceDocument, ExtractedRecord $draft): RedirectResponse
    {
        if ($draft->source_document_id !== $sourceDocument->id) {
            abort(404);
        }

        if (! $draft->isPending()) {
            return redirect()
                ->route('source-documents.show', $sourceDocument)
                ->with('status', 'That draft has already been reviewed.');
        }

        $dependents = $draft->findDependents();
        $idsToReject = $dependents->pluck('id')->push($draft->id)->all();

        DB::transaction(function () use ($idsToReject) {
            ExtractedRecord::whereIn('id', $idsToReject)->update(['status' => 'rejected']);
        });

        $rejectedCount = count($idsToReject);
        $message = $rejectedCount === 1
            ? 'Draft rejected.'
            : "Rejected {$rejectedCount} drafts.";

        // Navigate to the next draft in queue order. Since rejected
        // drafts are now visible in the queue, we don't filter by
        // status here — the user can still see what they just rejected.
        // If we're already at the last draft, stay on it so the user
        // sees the status update.
        $nextDraft = $this->findNextDraft($sourceDocument, $draft) ?? $draft;

        return redirect()
            ->route('source-documents.review.show', [
                'sourceDocument' => $sourceDocument,
                'draft' => $nextDraft->id,
            ])
            ->with('status', $message);
    }

    /**
     * Restore a rejected draft to pending status. Single-row update,
     * no cascade — if the draft references other drafts that are
     * still rejected, the user sees the broken references in the UI
     * and can choose to restore them too.
     *
     * Only rejected drafts can be restored via this action. Confirmed
     * and merged drafts have different rollback semantics (real records
     * created or synthesised descriptions persisted) and aren't in scope
     * here.
     */
    public function restore(SourceDocument $sourceDocument, ExtractedRecord $draft): RedirectResponse
    {
        if ($draft->source_document_id !== $sourceDocument->id) {
            abort(404);
        }

        if ($draft->status !== 'rejected') {
            return redirect()
                ->route('source-documents.review.show', [
                    'sourceDocument' => $sourceDocument,
                    'draft' => $draft->id,
                ])
                ->with('status', 'Only rejected drafts can be restored.');
        }

        $draft->update(['status' => 'pending']);

        return redirect()
            ->route('source-documents.review.show', [
                'sourceDocument' => $sourceDocument,
                'draft' => $draft->id,
            ])
            ->with('status', 'Draft restored to pending.');
    }

    /**
     * Find the next draft in queue order after $current. Walks all
     * drafts regardless of status — rejected drafts are visible in
     * the queue and shouldn't be skipped. Returns null if $current
     * is at the end of the queue.
     */
    private function findNextDraft(SourceDocument $sourceDocument, ExtractedRecord $current): ?ExtractedRecord
    {
        $queue = $this->allDraftsQuery($sourceDocument)->get();

        $foundCurrent = false;
        foreach ($queue as $candidate) {
            if ($foundCurrent) {
                return $candidate;
            }
            if ($candidate->id === $current->id) {
                $foundCurrent = true;
            }
        }

        return null;
    }

    /**
     * All drafts for a source document — pending, rejected, confirmed,
     * merged — ordered by record type (orgs first, accomplishments
     * last), then by id (creation order) within each type.
     */
    private function allDraftsQuery(SourceDocument $sourceDocument)
    {
        return $sourceDocument->extractedRecords()
            ->orderByRaw($this->typeOrderExpression())
            ->orderBy('id');
    }

    /**
     * Pending drafts for a source document, type-ordered. Used by
     * index() to pick the first pending draft to navigate to.
     */
    private function pendingDraftsQuery(SourceDocument $sourceDocument)
    {
        return $sourceDocument->extractedRecords()
            ->where('status', 'pending')
            ->orderByRaw($this->typeOrderExpression())
            ->orderBy('id');
    }

    /**
     * CASE expression for type-ordering. Works on both MySQL
     * (production) and SQLite (tests). Extracted from the two
     * query methods above so the ordering stays consistent.
     */
    private function typeOrderExpression(): string
    {
        $cases = collect(self::RECORD_TYPE_ORDER)
            ->map(fn ($order, $type) => "WHEN record_type = '{$type}' THEN {$order}")
            ->implode(' ');

        return "CASE {$cases} ELSE 99 END";
    }
}