<?php

namespace App\Http\Controllers;

use App\Models\ExtractedRecord;
use App\Models\Person;
use App\Models\SourceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller for the person review wizard step.
 *
 * Symmetric with TagReviewController minus the alias action — people
 * don't have an alias mechanism. Two actions (accept / reject) with
 * the same re-decidable pattern: each transition reverts any prior
 * decision's catalog mutations before applying the new state.
 *
 * Accept finds-or-creates a catalog Person by case-insensitive name.
 * Reject deletes the catalog person only when the prior accept created
 * it (tracked via `payload.catalog_person_created_by_review`).
 *
 * Action endpoints return JSON exclusively: {ok: true} on success,
 * {error: '...'} with a 4xx/5xx status on failure. The JS client
 * treats responses uniformly — same contract as tag review.
 */
class PersonReviewController extends Controller
{
    /**
     * The person review page.
     *
     * Renders every person review record for the document, regardless
     * of status. People aren't categorized, so the layout is a single
     * flat list — no category grouping like the tag review page.
     *
     * Mentions context works the same way as tag review: for each
     * person's extracted name, find which entity drafts reference that
     * person in their nested `collaborators` array. This helps the
     * user judge whether a name is worth keeping.
     *
     * Empty case (zero person review records): redirect onward — there's
     * no step to render.
     */
    public function show(SourceDocument $sourceDocument)
    {
        $records = ExtractedRecord::query()
            ->where('source_document_id', $sourceDocument->id)
            ->where('record_type', 'person')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return redirect()->route('source-documents.review.index', $sourceDocument);
        }

        // Build the mentions map. For each person review record's
        // extracted name, find which entity drafts in this document
        // have that name in their nested `collaborators` payload array.
        // The view uses this to render "Mentioned on: Acme (organization),
        // Migration (project)" under each person card.
        $entityDrafts = ExtractedRecord::query()
            ->where('source_document_id', $sourceDocument->id)
            ->whereIn('record_type', ['organization', 'position', 'project', 'accomplishment'])
            ->get();

        $mentions = [];
        foreach ($entityDrafts as $entityDraft) {
            $nestedCollaborators = $entityDraft->payload['collaborators'] ?? null;
            if (! is_array($nestedCollaborators)) {
                continue;
            }

            $displayName = $entityDraft->payload['name']
                ?? $entityDraft->payload['title']
                ?? '(untitled)';

            foreach ($nestedCollaborators as $collaborator) {
                if (! is_array($collaborator) || empty($collaborator['name'])) {
                    continue;
                }
                $key = strtolower(trim($collaborator['name']));
                if (! isset($mentions[$key])) {
                    $mentions[$key] = [];
                }
                $mentions[$key][] = [
                    'name' => $displayName,
                    'type' => $entityDraft->record_type,
                    'role' => $collaborator['role'] ?? null,
                ];
            }
        }

        return view('people-reviews.show', [
            'sourceDocument' => $sourceDocument,
            'records' => $records,
            'mentions' => $mentions,
        ]);
    }

    /**
     * Accept a person review record: find-or-create the catalog person
     * by case-insensitive name, set the record's status to confirmed,
     * link via match_record_id.
     *
     * Idempotent: re-accepting an already-confirmed record is a no-op
     * that returns success. State-transitioning from rejected: just
     * records the new state (no alias to clean up, unlike tag review).
     */
    public function accept(SourceDocument $sourceDocument, ExtractedRecord $record): JsonResponse
    {
        if (! $this->recordBelongsToDocument($record, $sourceDocument) || $record->record_type !== 'person') {
            return $this->errorResponse('This person is no longer available — please refresh the page.', 404);
        }

        try {
            DB::transaction(function () use ($record) {
                $this->revertPriorDecision($record);

                $payload = $record->payload ?? [];
                $name = $payload['extracted_name'] ?? null;

                $person = Person::query()
                    ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
                    ->first();

                $createdNow = false;
                if (! $person) {
                    $person = Person::create(['name' => trim($name)]);
                    $createdNow = true;
                }

                $newPayload = $payload;
                if ($createdNow) {
                    $newPayload['catalog_person_created_by_review'] = true;
                }

                $record->update([
                    'payload' => $newPayload,
                    'status' => 'confirmed',
                    'match_record_type' => 'person',
                    'match_record_id' => $person->id,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Person review accept failed', [
                'record_id' => $record->id,
                'document_id' => $sourceDocument->id,
                'exception' => $e,
            ]);
            return $this->errorResponse('Could not accept this person — please refresh the page and try again.', 500);
        }

        $record->refresh();
        $catalogPersonName = $record->match_record_id
            ? optional(Person::find($record->match_record_id))->name
            : null;

        return response()->json([
            'ok' => true,
            'catalog_person_name' => $catalogPersonName,
        ]);
    }

    /**
     * Reject a person review record. If a previous accept on this
     * record created a catalog person, delete it (the
     * `catalog_person_created_by_review` payload flag tracks this).
     *
     * Idempotent: re-rejecting is a no-op.
     */
    public function reject(SourceDocument $sourceDocument, ExtractedRecord $record): JsonResponse
    {
        if (! $this->recordBelongsToDocument($record, $sourceDocument) || $record->record_type !== 'person') {
            return $this->errorResponse('This person is no longer available — please refresh the page.', 404);
        }

        try {
            DB::transaction(function () use ($record) {
                $this->revertPriorDecision($record);

                $payload = $record->payload ?? [];
                unset($payload['catalog_person_created_by_review']);

                $record->update([
                    'payload' => $payload,
                    'status' => 'rejected',
                    'match_record_type' => null,
                    'match_record_id' => null,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Person review reject failed', [
                'record_id' => $record->id,
                'document_id' => $sourceDocument->id,
                'exception' => $e,
            ]);
            return $this->errorResponse('Could not reject this person — please refresh the page and try again.', 500);
        }

        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function recordBelongsToDocument(ExtractedRecord $record, SourceDocument $document): bool
    {
        return $record->source_document_id === $document->id;
    }

    /**
     * Revert any catalog mutations this review record previously made.
     * Simpler than the tag review equivalent — no alias row to clean up,
     * only a catalog person we may have created via a previous accept.
     */
    private function revertPriorDecision(ExtractedRecord $record): void
    {
        $payload = $record->payload ?? [];

        if (! empty($payload['catalog_person_created_by_review']) && $record->match_record_id) {
            Person::where('id', $record->match_record_id)->delete();
        }
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}