<?php

namespace App\Http\Controllers;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Services\Drafts\DraftConfirmationException;
use App\Services\Drafts\DraftConfirmer;
use App\Services\Drafts\DraftFieldSchema;
use App\Services\Drafts\DuplicateDetector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     *
     * For pending drafts, also runs duplicate detection so the action
     * bar can surface a "Merge into..." affordance alongside Confirm
     * and Reject. Skipped for non-pending drafts since the merge
     * action isn't available there anyway.
     */
    public function show(
        SourceDocument $sourceDocument,
        ExtractedRecord $draft,
        DuplicateDetector $detector,
    ): View|RedirectResponse {
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

        // Duplicate-merge candidates. Same pending-only gate —
        // already-reviewed drafts don't offer the merge action, so
        // detection would just waste a query (and, in the org case,
        // load every org into memory for the in-PHP substring filter).
        $mergeCandidates = $draft->isPending()
            ? $detector->findCandidates($draft)
            : collect();

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
            'mergeCandidates' => $mergeCandidates,
            'fieldSchema' => DraftFieldSchema::for($draft->record_type),
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
     * Confirm a pending draft — merge the user's form edits into the
     * payload, persist the updated payload, then create the real
     * catalog record. Mark the draft as confirmed and navigate to
     * the next draft on success.
     *
     * Form fields match the keys defined in DraftFieldSchema for the
     * draft's record_type. Whatever the user submits replaces the
     * matching keys in the payload; fields not in the schema are
     * left alone. This lets the user fill in fields the AI omitted
     * (the original motivation for the form) without losing any
     * AI-extracted data that isn't on the form.
     *
     * Edits persist even when confirmation fails (e.g., a referenced
     * parent doesn't exist yet) so the user doesn't lose their work
     * if they need to confirm an earlier draft first.
     *
     * Parent references in the payload (organization_name,
     * position_title, etc.) are resolved to foreign keys by exact-name
     * lookup in the existing catalog. If a parent can't be resolved,
     * we stay on the current draft with an explanatory flash message.
     *
     * Duplicate detection ("this draft probably matches an existing
     * record, want to merge?") is slice 4.5's concern. This action
     * always creates a new record.
     */
    public function confirm(
        Request $request,
        SourceDocument $sourceDocument,
        ExtractedRecord $draft,
        DraftConfirmer $confirmer,
    ): RedirectResponse {
        if ($draft->source_document_id !== $sourceDocument->id) {
            abort(404);
        }

        // Merge form input into the payload. Only keys present in
        // the schema are considered (so nothing outside the form can
        // sneak in). Empty inputs become null and overwrite the
        // existing payload value — clearing an input deliberately
        // clears that field. Untouched inputs come back with their
        // current value and merge in unchanged.
        $schema = DraftFieldSchema::for($draft->record_type);
        $formData = $request->only(array_keys($schema));
        $payload = array_merge($draft->payload ?? [], $this->normalizeFormData($formData));

        $draft->update(['payload' => $payload]);

        try {
            $confirmer->confirm($draft->fresh());
        } catch (DraftConfirmationException $e) {
            return redirect()
                ->route('source-documents.review.show', [
                    'sourceDocument' => $sourceDocument,
                    'draft' => $draft->id,
                ])
                ->with('status', $e->getMessage());
        }

        // Navigate to the next draft in queue. If we're at the end,
        // stay on the current one so the user sees the confirmed badge.
        $nextDraft = $this->findNextDraft($sourceDocument, $draft) ?? $draft;

        return redirect()
            ->route('source-documents.review.show', [
                'sourceDocument' => $sourceDocument,
                'draft' => $nextDraft->id,
            ])
            ->with('status', 'Draft confirmed.');
    }

    /**
     * Normalize form input for merging into the payload. Empty
     * strings become null (so they don't pass schema NOT NULL checks
     * via a stringy ''). Trims whitespace from strings. Leaves arrays
     * alone — none of the current schema uses array fields, but if
     * one's added later it won't be mangled.
     */
    private function normalizeFormData(array $formData): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                return $trimmed === '' ? null : $trimmed;
            }
            return $value;
        }, $formData);
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
     * All drafts for a source document, type-ordered (organization
     * first, then position, project, accomplishment), then by id
     * (creation order) within each type.
     *
     * Scoped to entity-type drafts only — tag/person/link review
     * records produced by ReviewRecordExtractor live in the same
     * extracted_records table but don't belong in this queue. They
     * have a different action set (confirm/reject/merge with existing/
     * add as alias) and the chunk-4 review UI will provide a separate
     * surface for them. Including them here would make the queue's
     * Confirm action fail with "Unknown record type" since
     * DraftConfirmer has no dispatch arm for tag or link.
     */
    private function allDraftsQuery(SourceDocument $sourceDocument)
    {
        return $sourceDocument->extractedRecords()
            ->whereIn('record_type', array_keys(self::RECORD_TYPE_ORDER))
            ->orderByRaw($this->typeOrderExpression())
            ->orderBy('id');
    }

    /**
     * Pending drafts for a source document, type-ordered. Used by
     * index() to pick the first pending draft to navigate to.
     * Same entity-only scoping as allDraftsQuery — see its docblock.
     */
    private function pendingDraftsQuery(SourceDocument $sourceDocument)
    {
        return $sourceDocument->extractedRecords()
            ->where('status', 'pending')
            ->whereIn('record_type', array_keys(self::RECORD_TYPE_ORDER))
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