<?php

namespace App\Http\Controllers;

use App\Models\ExtractedRecord;
use App\Models\Link;
use App\Models\SourceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller for the link review wizard step.
 *
 * Dedicated review page for accepting/rejecting/editing extracted
 * links, symmetric with TagReviewController and PersonReviewController.
 * Links are reviewed document-wide (not per-entity-draft) because
 * ReviewRecordExtractor dedupes by URL across all entity drafts.
 *
 * Accept means "this link should materialize when its parent entity
 * draft is confirmed." Reject means "skip this link." Field edits
 * (url, type, title, description, is_personal_appearance) update the
 * review record's payload — at confirmation time, attachNestedLinks
 * reads from the review record's payload where present.
 *
 * No catalog creation on accept — links don't have a standalone
 * catalog entry until the parent entity draft is confirmed and
 * attachNestedLinks materializes them.
 *
 * Action endpoints return JSON, same contract as tag and person review.
 */
class LinkReviewController extends Controller
{
    /**
     * The link review page.
     *
     * Renders every link review record for the document, regardless
     * of status. Links aren't categorized, so the layout is a single
     * flat list — same as person review.
     *
     * Mentions context: for each link's URL, find which entity drafts
     * reference it in their nested `links` array. Helps the user judge
     * whether a link is worth keeping.
     *
     * Empty case (zero link review records): redirect onward.
     */
    public function show(SourceDocument $sourceDocument)
    {
        $records = ExtractedRecord::query()
            ->where('source_document_id', $sourceDocument->id)
            ->where('record_type', 'link')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return redirect()->route('source-documents.review.index', $sourceDocument);
        }

        // Build the mentions map. For each link review record's URL,
        // find which entity drafts reference that URL in their nested
        // `links` payload array.
        $entityDrafts = ExtractedRecord::query()
            ->where('source_document_id', $sourceDocument->id)
            ->whereIn('record_type', ['organization', 'position', 'project', 'accomplishment'])
            ->get();

        $mentions = [];
        foreach ($entityDrafts as $entityDraft) {
            $nestedLinks = $entityDraft->payload['links'] ?? null;
            if (! is_array($nestedLinks)) {
                continue;
            }

            $displayName = $entityDraft->payload['name']
                ?? $entityDraft->payload['title']
                ?? '(untitled)';

            foreach ($nestedLinks as $nestedLink) {
                if (! is_array($nestedLink) || empty($nestedLink['url'])) {
                    continue;
                }
                $key = trim($nestedLink['url']);
                if (! isset($mentions[$key])) {
                    $mentions[$key] = [];
                }
                $mentions[$key][] = [
                    'name' => $displayName,
                    'type' => $entityDraft->record_type,
                ];
            }
        }

        return view('link-reviews.show', [
            'sourceDocument' => $sourceDocument,
            'records' => $records,
            'mentions' => $mentions,
        ]);
    }

    /**
     * Accept a link review record. Sets status to confirmed.
     */
    public function accept(SourceDocument $sourceDocument, ExtractedRecord $record): JsonResponse
    {
        if (! $this->recordBelongsToDocument($record, $sourceDocument) || $record->record_type !== 'link') {
            return $this->errorResponse('This link is no longer available — please refresh the page.', 404);
        }

        try {
            $record->update(['status' => 'confirmed']);
        } catch (\Throwable $e) {
            Log::error('Link review accept failed', [
                'record_id' => $record->id,
                'document_id' => $sourceDocument->id,
                'exception' => $e,
            ]);
            return $this->errorResponse('Could not accept this link — please refresh the page and try again.', 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Reject a link review record. The link will be skipped by
     * attachNestedLinks at confirmation time.
     */
    public function reject(SourceDocument $sourceDocument, ExtractedRecord $record): JsonResponse
    {
        if (! $this->recordBelongsToDocument($record, $sourceDocument) || $record->record_type !== 'link') {
            return $this->errorResponse('This link is no longer available — please refresh the page.', 404);
        }

        try {
            $record->update(['status' => 'rejected']);
        } catch (\Throwable $e) {
            Log::error('Link review reject failed', [
                'record_id' => $record->id,
                'document_id' => $sourceDocument->id,
                'exception' => $e,
            ]);
            return $this->errorResponse('Could not reject this link — please refresh the page and try again.', 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Update a link review record's payload fields. Editable fields:
     * url, type, title, description, is_personal_appearance. Edits
     * are stored on the review record's payload — the entity draft's
     * nested array stays untouched (immutable extracted data).
     *
     * The update is orthogonal to accept/reject — the user can edit
     * regardless of status. The response echoes back the updated
     * payload so the JS can refresh the display.
     */
    public function update(Request $request, SourceDocument $sourceDocument, ExtractedRecord $record): JsonResponse
    {
        if (! $this->recordBelongsToDocument($record, $sourceDocument) || $record->record_type !== 'link') {
            return $this->errorResponse('This link is no longer available — please refresh the page.', 404);
        }

        $payload = $record->payload ?? [];

        if ($request->has('url')) {
            $url = is_string($request->input('url')) ? trim($request->input('url')) : '';
            $payload['url'] = $url !== '' ? $url : null;
        }

        if ($request->has('type')) {
            $type = $request->input('type');
            if (is_string($type) && in_array($type, Link::TYPES, true)) {
                $payload['type'] = $type;
            }
        }

        if ($request->has('title')) {
            $title = is_string($request->input('title')) ? trim($request->input('title')) : '';
            $payload['title'] = $title !== '' ? $title : null;
        }

        if ($request->has('description')) {
            $desc = is_string($request->input('description')) ? trim($request->input('description')) : '';
            $payload['description'] = $desc !== '' ? $desc : null;
        }

        if ($request->has('is_personal_appearance')) {
            $payload['is_personal_appearance'] = (bool) $request->input('is_personal_appearance');
        }

        try {
            $record->update(['payload' => $payload]);
        } catch (\Throwable $e) {
            Log::error('Link review update failed', [
                'record_id' => $record->id,
                'document_id' => $sourceDocument->id,
                'exception' => $e,
            ]);
            return $this->errorResponse('Could not update this link — please refresh the page and try again.', 500);
        }

        return response()->json([
            'ok' => true,
            'payload' => $record->fresh()->payload,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function recordBelongsToDocument(ExtractedRecord $record, SourceDocument $document): bool
    {
        return $record->source_document_id === $document->id;
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}