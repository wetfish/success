<?php

namespace App\Http\Controllers;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Services\AiUsageTracker;
use App\Services\Drafts\DraftFieldSchema;
use App\Services\Drafts\DraftMerger;
use App\Services\Drafts\DraftMergerException;
use App\Services\Drafts\DuplicateDetector;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Handles the merge flow when duplicate detection has surfaced one
 * or more existing records the user can merge a pending draft into.
 *
 * Three actions:
 *
 *   show(GET)        — render the candidate picker (no candidate_id
 *                      yet, or multiple matches) or the side-by-side
 *                      editor (candidate_id resolves to one of the
 *                      detected candidates).
 *   synthesize(POST) — JSON endpoint: combine two text values via
 *                      the extraction provider and return the merged
 *                      string. Used by the "Synthesize" button on
 *                      textarea fields in the editor.
 *   store(POST)      — execute the merge with the user's chosen
 *                      per-field values, then navigate to the next
 *                      draft in queue order.
 *
 * Detection runs in DraftReviewController::show; by the time the
 * user lands here, the action bar on the review page has already
 * told them merge is on offer. This controller re-runs detection on
 * every request anyway, so a stale URL or a race condition (catalog
 * changed in another tab) can never produce a merge against an
 * arbitrary record.
 */
class DraftMergeController extends Controller
{
    /**
     * Queue type ordering — kept in lockstep with the same constant
     * in DraftReviewController. When the merge controller picks
     * "next draft after this one," it has to walk the queue in the
     * same order the review screen uses or the user would land on
     * an unexpected draft.
     *
     * TODO: extract the queue-walking helpers (this constant,
     * findNextDraft, typeOrderExpression) onto ExtractedRecord
     * itself or a small QueueNavigator service. Duplicated for
     * slice 4.5 to avoid crossing architectural layers in a single
     * chunk; consolidate in a follow-up commit.
     */
    private const RECORD_TYPE_ORDER = [
        'organization' => 1,
        'position' => 2,
        'project' => 3,
        'accomplishment' => 4,
    ];

    /**
     * Render the merge page. The same Blade view handles both modes
     * — when `$candidate` is null it renders the picker; when it's
     * resolved it renders the side-by-side editor.
     *
     * If detection finds no candidates (the user typed the URL by
     * hand, or the catalog changed between draft load and this
     * request), bounce back to the review show page with a flash.
     */
    public function show(
        Request $request,
        SourceDocument $sourceDocument,
        ExtractedRecord $draft,
        DuplicateDetector $detector,
    ): View|RedirectResponse {
        if ($draft->source_document_id !== $sourceDocument->id) {
            abort(404);
        }

        if (! $draft->isPending()) {
            return redirect()
                ->route('source-documents.review.show', [
                    'sourceDocument' => $sourceDocument,
                    'draft' => $draft->id,
                ])
                ->with('status', 'Only pending drafts can be merged.');
        }

        $candidates = $detector->findCandidates($draft);
        if ($candidates->isEmpty()) {
            return redirect()
                ->route('source-documents.review.show', [
                    'sourceDocument' => $sourceDocument,
                    'draft' => $draft->id,
                ])
                ->with('status', 'No existing records match this draft.');
        }

        // Resolve the candidate the user has selected via ?candidate_id=.
        // The id MUST appear in the freshly-detected list — this guards
        // against a stale URL pointing at a record that no longer
        // qualifies, or an arbitrary id crafted by hand. Unresolved
        // ids fall through to the picker.
        $candidateId = $request->query('candidate_id');
        $candidate = $candidateId !== null
            ? $candidates->firstWhere('id', (int) $candidateId)
            : null;

        return view('draft-reviews.merge', [
            'sourceDocument' => $sourceDocument,
            'draft' => $draft,
            'candidates' => $candidates,
            'candidate' => $candidate,
            'fieldSchema' => DraftFieldSchema::for($draft->record_type),
        ]);
    }

    /**
     * JSON endpoint: combine two text values via the extraction
     * provider and return the synthesized string. Logs an
     * AiUsageEvent on both success and failure paths so cost
     * telemetry reflects everything attempted, not just what worked.
     *
     * Request body:
     *   - existing: string  the existing record's value for the field
     *   - draft:    string  the draft's value for the field
     *
     * Both are accepted as empty strings — the provider's prompt
     * handles empty inputs gracefully, and we don't want to block
     * synthesis when one side happens to be blank.
     *
     * Response:
     *   200 { "synthesized": "..." }    on success
     *   422 { "error": "..." }          when the draft isn't pending
     *   502 { "error": "..." }          on provider failure
     */
    public function synthesize(
        Request $request,
        SourceDocument $sourceDocument,
        ExtractedRecord $draft,
        ExtractionProvider $provider,
        AiUsageTracker $tracker,
    ): JsonResponse {
        if ($draft->source_document_id !== $sourceDocument->id) {
            abort(404);
        }

        if (! $draft->isPending()) {
            return response()->json([
                'error' => 'This draft has already been reviewed.',
            ], 422);
        }

        // The ConvertEmptyStringsToNull middleware turns "" into null
        // before validation runs, so `string` alone would reject a
        // legitimately empty side. `nullable` accepts that null; we
        // coerce back to "" below before passing to the provider,
        // whose synthesize() signature is string|string.
        $validated = $request->validate([
            'existing' => 'present|nullable|string',
            'draft' => 'present|nullable|string',
        ]);

        try {
            $result = $provider->synthesize(
                $validated['existing'] ?? '',
                $validated['draft'] ?? '',
            );
        } catch (ExtractionException $e) {
            $tracker->recordFailure(
                provider: $provider->name(),
                model: 'unknown',
                operation: 'synthesize',
                errorMessage: $e->getMessage(),
            );
            return response()->json([
                'error' => 'Synthesis failed. Pick existing or draft directly, or try again.',
            ], 502);
        }

        $tracker->recordSynthesis($result, $provider->name());

        return response()->json([
            'synthesized' => $result->description,
        ]);
    }

    /**
     * Execute the merge with the user's chosen per-field values.
     * Navigate to the next draft on success; stay on the editor
     * with a flash message on any failure path.
     *
     * Body shape:
     *   - candidate_id: int                  required, must be in
     *                                        the detected candidate set
     *   - fields[<payload_key>]: string|null per-field chosen value
     */
    public function store(
        Request $request,
        SourceDocument $sourceDocument,
        ExtractedRecord $draft,
        DuplicateDetector $detector,
        DraftMerger $merger,
    ): RedirectResponse {
        if ($draft->source_document_id !== $sourceDocument->id) {
            abort(404);
        }

        if (! $draft->isPending()) {
            return redirect()
                ->route('source-documents.review.show', [
                    'sourceDocument' => $sourceDocument,
                    'draft' => $draft->id,
                ])
                ->with('status', 'Only pending drafts can be merged.');
        }

        $candidateId = (int) $request->input('candidate_id', 0);
        if ($candidateId <= 0) {
            return $this->backToMerge(
                $sourceDocument,
                $draft,
                'Pick a target record before merging.',
            );
        }

        // Re-run detection and verify the submitted candidate is
        // still in the valid set. Defends against stale tabs and
        // hand-crafted candidate_ids pointing at unrelated records.
        $candidates = $detector->findCandidates($draft);
        $candidate = $candidates->firstWhere('id', $candidateId);

        if ($candidate === null) {
            return $this->backToMerge(
                $sourceDocument,
                $draft,
                'That target record is no longer a valid match. Pick another.',
            );
        }

        $fields = $request->input('fields', []);
        if (! is_array($fields)) {
            $fields = [];
        }
        $fields = $this->normalizeFieldChoices($fields);

        try {
            $merger->merge($draft, $candidate, $fields);
        } catch (DraftMergerException $e) {
            return $this->backToMerge(
                $sourceDocument,
                $draft,
                $e->getMessage(),
                $candidate->id,
            );
        }

        // Navigate to the next draft in queue order. If we're at
        // the end, stay on the just-merged draft so the user sees
        // the merged badge update.
        $nextDraft = $this->findNextDraft($sourceDocument, $draft) ?? $draft;

        return redirect()
            ->route('source-documents.review.show', [
                'sourceDocument' => $sourceDocument,
                'draft' => $nextDraft->id,
            ])
            ->with('status', 'Draft merged.');
    }

    /**
     * Redirect back to the merge show page with a flash message.
     * Preserves the candidate_id when one was already chosen so
     * the user lands back in the editor rather than the picker.
     */
    private function backToMerge(
        SourceDocument $sourceDocument,
        ExtractedRecord $draft,
        string $message,
        ?int $candidateId = null,
    ): RedirectResponse {
        $params = [
            'sourceDocument' => $sourceDocument,
            'draft' => $draft->id,
        ];
        if ($candidateId !== null) {
            $params['candidate_id'] = $candidateId;
        }
        return redirect()
            ->route('source-documents.review.merge.show', $params)
            ->with('status', $message);
    }

    /**
     * Trim strings and convert empty strings to null. Mirrors
     * DraftReviewController::normalizeFormData() so the two action
     * paths treat user input consistently.
     */
    private function normalizeFieldChoices(array $fields): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                return $trimmed === '' ? null : $trimmed;
            }
            return $value;
        }, $fields);
    }

    /**
     * Find the next draft after $current in queue order. Walks all
     * drafts regardless of status. Returns null if $current is at
     * the end of the queue.
     *
     * TODO: duplicates DraftReviewController::findNextDraft. See
     * the class-level note on RECORD_TYPE_ORDER for consolidation.
     */
    private function findNextDraft(SourceDocument $sourceDocument, ExtractedRecord $current): ?ExtractedRecord
    {
        $queue = $sourceDocument->extractedRecords()
            ->orderByRaw($this->typeOrderExpression())
            ->orderBy('id')
            ->get();

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

    private function typeOrderExpression(): string
    {
        $cases = collect(self::RECORD_TYPE_ORDER)
            ->map(fn ($order, $type) => "WHEN record_type = '{$type}' THEN {$order}")
            ->implode(' ');

        return "CASE {$cases} ELSE 99 END";
    }
}