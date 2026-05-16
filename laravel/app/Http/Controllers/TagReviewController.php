<?php

namespace App\Http\Controllers;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Models\Tag;
use App\Models\TagAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller for the tag review wizard step.
 *
 * The page (show) renders all of a source document's tag review records
 * with status='pending'. Already-confirmed records (matched at
 * derivation time, see ReviewRecordExtractor) don't appear — the
 * user has nothing to decide on those.
 *
 * Three action endpoints (accept / reject / alias) are state transitions
 * that handle any starting state, not strictly pending-only. This
 * supports the user's natural workflow: accept → change their mind →
 * reject → change their mind → accept again. Each transition cleans
 * up the previous decision's catalog mutations (delete tag, delete
 * alias) before applying the new state.
 *
 * Action endpoints return JSON exclusively: {ok: true} on success,
 * {error: '...'} with a 4xx/5xx status on failure. No partial HTML
 * — the JS client treats responses uniformly. The view itself is
 * rendered server-side once on initial load; all subsequent UI
 * updates are client-driven.
 *
 * The catalog-tag deletion logic uses a payload flag
 * (`catalog_tag_created_by_review`) to track which catalog tags this
 * review record itself created. Tags that already existed before
 * review aren't deleted on reject — we only undo our own mutations.
 */
class TagReviewController extends Controller
{
    /**
     * The tag review page.
     *
     * Renders every tag review record for the document, regardless of
     * status. The page is the audit-trail surface: users can see what
     * the AI extracted, what they decided, and re-decide if they want
     * to (a future feature; for MVP the actions on already-decided
     * records just retake the transition).
     *
     * Records are grouped by AI-emitted category for scannability.
     * Categories with zero records don't render — keeps the page
     * focused on what's actually there.
     *
     * Edge case: if the document has zero tag review records at all
     * (e.g., the AI extracted no tags), redirect onward — there's no
     * step to render. The middleware lets this case through because
     * "pending records" is zero. The redirect is only on the empty
     * case, not on "all decided" — users with reviewed-everything
     * documents still see the page so they can review past decisions.
     */
    public function show(SourceDocument $sourceDocument)
    {
        $records = ExtractedRecord::query()
            ->where('source_document_id', $sourceDocument->id)
            ->where('record_type', 'tag')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return redirect()->route('source-documents.review.index', $sourceDocument);
        }

        // Group by AI-emitted category. Records with no category land
        // under a synthetic 'uncategorized' bucket so they still get
        // surfaced rather than vanishing. The bucket order is fixed
        // (matches Tag::CATEGORIES) so the layout is stable across
        // page loads.
        $categoryOrder = array_merge(Tag::CATEGORIES, ['uncategorized']);
        $grouped = collect($categoryOrder)->mapWithKeys(fn ($cat) => [$cat => collect()])->all();
        foreach ($records as $record) {
            $cat = $record->payload['category'] ?? null;
            if (! is_string($cat) || ! in_array($cat, Tag::CATEGORIES, true)) {
                $cat = 'uncategorized';
            }
            $grouped[$cat]->push($record);
        }
        // Drop empty categories from the rendered list — keeps the page
        // focused on what's actually there.
        $grouped = collect($grouped)->filter(fn ($records) => $records->isNotEmpty())->all();

        // Build the mentions map. For each tag review record's extracted
        // name, find which entity drafts in this document have that name
        // in their nested `tags` payload array. The view uses this to
        // render the "Mentioned on: Acme (organization), Migration
        // (project)" line under each tag card.
        //
        // One round-trip fetches all entity drafts; the join is in-PHP
        // because the nested tags live in a JSON payload column.
        $entityDrafts = ExtractedRecord::query()
            ->where('source_document_id', $sourceDocument->id)
            ->whereIn('record_type', ['organization', 'position', 'project', 'accomplishment'])
            ->get();

        $mentions = [];
        foreach ($entityDrafts as $entityDraft) {
            $nestedTags = $entityDraft->payload['tags'] ?? null;
            if (! is_array($nestedTags)) {
                continue;
            }
            // For org drafts the display field is "name"; for positions
            // it's "title"; projects use "name"; accomplishments use
            // "title". Prefer name, fall back to title.
            $displayName = $entityDraft->payload['name']
                ?? $entityDraft->payload['title']
                ?? '(untitled)';

            foreach ($nestedTags as $nestedTag) {
                if (! is_array($nestedTag) || empty($nestedTag['name'])) {
                    continue;
                }
                $key = strtolower(trim($nestedTag['name']));
                if (! isset($mentions[$key])) {
                    $mentions[$key] = [];
                }
                $mentions[$key][] = [
                    'name' => $displayName,
                    'type' => $entityDraft->record_type,
                ];
            }
        }

        return view('tag-reviews.show', [
            'sourceDocument' => $sourceDocument,
            'grouped' => $grouped,
            'mentions' => $mentions,
        ]);
    }

    /**
     * Accept a tag review record: create the catalog tag (or find an
     * existing one matching the name), set the record's status to
     * confirmed, link via match_record_id.
     *
     * Idempotent: re-accepting an already-confirmed record is a no-op
     * that returns success. State-transitioning from rejected or
     * merged: cleans up the previous decision's mutations first
     * (a previous 'merged' state means there's an alias row to remove).
     */
    public function accept(SourceDocument $sourceDocument, ExtractedRecord $record): JsonResponse
    {
        if (! $this->recordBelongsToDocument($record, $sourceDocument) || $record->record_type !== 'tag') {
            return $this->errorResponse('This tag is no longer available — please refresh the page.', 404);
        }

        try {
            DB::transaction(function () use ($record) {
                $this->revertPriorDecision($record);

                $payload = $record->payload ?? [];
                $name = $payload['extracted_name'] ?? null;
                $category = $payload['category'] ?? null;

                // Find-or-create on the catalog. If a catalog tag with
                // this name already exists (e.g., a separate accept
                // accepted "Postgres" earlier in the same review
                // session), we attach to it rather than fail.
                $tag = Tag::query()
                    ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
                    ->first();

                $createdNow = false;
                if (! $tag) {
                    $validCategory = $category !== null && in_array($category, Tag::CATEGORIES, true)
                        ? $category
                        : null;
                    $tag = Tag::create(['name' => trim($name), 'category' => $validCategory]);
                    $createdNow = true;
                }

                // Mark in the payload that this review record created
                // the catalog tag (only when we did so just now — not
                // when find-by-name attached to a pre-existing one).
                $newPayload = $payload;
                if ($createdNow) {
                    $newPayload['catalog_tag_created_by_review'] = true;
                }

                $record->update([
                    'payload' => $newPayload,
                    'status' => 'confirmed',
                    'match_record_type' => 'tag',
                    'match_record_id' => $tag->id,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Tag review accept failed', [
                'record_id' => $record->id,
                'document_id' => $sourceDocument->id,
                'exception' => $e,
            ]);
            return $this->errorResponse('Could not accept this tag — please refresh the page and try again.', 500);
        }

        // Re-fetch the freshly-attached tag's display name so the JS
        // can render "Accepted as <name>" with the canonical casing
        // (which may differ from extracted_name if the find branch
        // matched an existing catalog tag).
        $record->refresh();
        $catalogTagName = $record->match_record_id
            ? optional(Tag::find($record->match_record_id))->name
            : null;

        return response()->json([
            'ok' => true,
            'catalog_tag_name' => $catalogTagName,
        ]);
    }

    /**
     * Reject a tag review record. If a previous accept on this record
     * created a catalog tag, delete it (the `catalog_tag_created_by_review`
     * payload flag tracks this). If a previous alias action created an
     * alias row, delete it. The user's rejection unwinds any prior
     * mutations.
     *
     * Idempotent: re-rejecting is a no-op.
     */
    public function reject(SourceDocument $sourceDocument, ExtractedRecord $record): JsonResponse
    {
        if (! $this->recordBelongsToDocument($record, $sourceDocument) || $record->record_type !== 'tag') {
            return $this->errorResponse('This tag is no longer available — please refresh the page.', 404);
        }

        try {
            DB::transaction(function () use ($record) {
                $this->revertPriorDecision($record);

                $payload = $record->payload ?? [];
                unset($payload['catalog_tag_created_by_review']);

                $record->update([
                    'payload' => $payload,
                    'status' => 'rejected',
                    'match_record_type' => null,
                    'match_record_id' => null,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Tag review reject failed', [
                'record_id' => $record->id,
                'document_id' => $sourceDocument->id,
                'exception' => $e,
            ]);
            return $this->errorResponse('Could not reject this tag — please refresh the page and try again.', 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Alias a tag review record to an existing catalog tag. Creates
     * a TagAlias row from extracted_name → target_tag, sets the
     * record's status to merged.
     *
     * The target_tag_id comes from the request body. Validation:
     *   - The target tag must exist in the catalog
     *   - The target tag must be a real tag, not the source of an alias
     *     itself (we don't chain aliases through other aliases)
     *   - The extracted_name must not already be a globally-registered
     *     alias (tag_aliases.alias has a unique constraint at the
     *     database level — we return a friendly error before hitting
     *     the constraint)
     *
     * State-transitioning from accepted: deletes any catalog tag we
     * previously created. From rejected: just records the new state.
     */
    public function alias(SourceDocument $sourceDocument, ExtractedRecord $record, Request $request): JsonResponse
    {
        if (! $this->recordBelongsToDocument($record, $sourceDocument) || $record->record_type !== 'tag') {
            return $this->errorResponse('This tag is no longer available — please refresh the page.', 404);
        }

        $targetTagId = $request->input('target_tag_id');
        if (! is_numeric($targetTagId)) {
            return $this->errorResponse('A target tag must be selected.', 422);
        }

        $targetTag = Tag::find($targetTagId);
        if (! $targetTag) {
            return $this->errorResponse('The selected target tag no longer exists.', 422);
        }

        $payload = $record->payload ?? [];
        $extractedName = $payload['extracted_name'] ?? null;
        if (! is_string($extractedName) || trim($extractedName) === '') {
            return $this->errorResponse('This record has no extracted name to alias.', 422);
        }

        // Self-alias guard: don't let the user alias a tag to itself.
        // If a previous accept on this record created a catalog tag
        // matching the target, the next step (revertPriorDecision)
        // would delete that catalog tag — leaving no target. Reject
        // up-front rather than letting the user create a broken state.
        if ($record->match_record_id === $targetTag->id && $record->status === 'confirmed') {
            return $this->errorResponse('That target is the same as the tag this record created. Pick a different target.', 422);
        }

        // Pre-check the global unique constraint on tag_aliases.alias
        // (a friendly error beats a database exception). Skip the
        // check if our own record already owns this alias from a
        // prior alias action — we'll be re-using/updating it.
        $existingAlias = TagAlias::query()
            ->whereRaw('LOWER(alias) = ?', [strtolower(trim($extractedName))])
            ->first();
        if ($existingAlias && $existingAlias->tag_id !== $targetTag->id) {
            return $this->errorResponse("\"{$extractedName}\" is already an alias of another tag. Pick a different target.", 422);
        }

        try {
            DB::transaction(function () use ($record, $targetTag, $extractedName, $payload) {
                $this->revertPriorDecision($record);

                // Create the alias unless it already exists pointing
                // at this target (idempotent re-alias to the same target).
                TagAlias::firstOrCreate(
                    ['alias' => trim($extractedName)],
                    ['tag_id' => $targetTag->id],
                );

                $newPayload = $payload;
                unset($newPayload['catalog_tag_created_by_review']);

                $record->update([
                    'payload' => $newPayload,
                    'status' => 'merged',
                    'match_record_type' => 'tag',
                    'match_record_id' => $targetTag->id,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Tag review alias failed', [
                'record_id' => $record->id,
                'document_id' => $sourceDocument->id,
                'target_tag_id' => $targetTag->id,
                'exception' => $e,
            ]);
            return $this->errorResponse('Could not alias this tag — please refresh the page and try again.', 500);
        }

        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Source-document scope guard. The {record} parameter is bound
     * via implicit model binding, but it could be any extracted_record
     * — including one from a different document. This check ensures
     * we don't act on cross-document records.
     */
    private function recordBelongsToDocument(ExtractedRecord $record, SourceDocument $document): bool
    {
        return $record->source_document_id === $document->id;
    }

    /**
     * Revert any catalog mutations this review record previously made.
     * Called at the top of every action transition (accept/reject/alias)
     * so the new state lands cleanly on a clean baseline.
     *
     * Two mutations to potentially revert:
     *   - A catalog tag we created via a previous accept (flagged by
     *     payload.catalog_tag_created_by_review). Deleting cascades to
     *     any tag_aliases pointing at it, so a prior alias-then-accept
     *     sequence cleans up its alias too.
     *   - An alias row we created via a previous alias action. Identified
     *     by alias = payload.extracted_name. We delete it regardless of
     *     target — even if some other process re-purposed the alias to
     *     point elsewhere, our review record's history is the alias's
     *     reason for existing, so unwinding the decision unwinds the row.
     */
    private function revertPriorDecision(ExtractedRecord $record): void
    {
        $payload = $record->payload ?? [];

        // Revert prior accept's catalog tag creation.
        if (! empty($payload['catalog_tag_created_by_review']) && $record->match_record_id) {
            Tag::where('id', $record->match_record_id)->delete();
            // Cascade handles tag_aliases pointing at this tag — see
            // tag_aliases migration's onDelete('cascade').
        }

        // Revert prior alias action.
        if ($record->status === 'merged' && isset($payload['extracted_name'])) {
            TagAlias::where('alias', $payload['extracted_name'])->delete();
        }
    }

    /**
     * Standard error response shape: {error: "human-readable message"}
     * with the appropriate HTTP status. The JS client checks for the
     * `error` key and displays it inline near the record's row.
     */
    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}