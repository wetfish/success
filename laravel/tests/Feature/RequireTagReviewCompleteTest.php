<?php

namespace Tests\Feature;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the RequireTagReviewComplete middleware.
 *
 * The middleware sits in front of entity-draft routes and redirects
 * to the tag review page when pending tag records exist for the
 * document. These tests verify the redirect happens at the right
 * times and doesn't happen when it shouldn't.
 */
class RequireTagReviewCompleteTest extends TestCase
{
    use RefreshDatabase;

    private function makeDocument(): SourceDocument
    {
        return SourceDocument::create([
            'title' => 'Test',
            'kind' => 'other',
            'file_type' => 'text',
            'body' => 'Test body',
        ]);
    }

    private function makeEntityDraft(SourceDocument $doc): ExtractedRecord
    {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'organization',
            'payload' => ['name' => 'Acme', 'type' => 'employer'],
            'status' => 'pending',
        ]);
    }

    private function makePendingTagReview(SourceDocument $doc, string $name = 'Postgres'): ExtractedRecord
    {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'tag',
            'payload' => ['extracted_name' => $name, 'category' => 'tool'],
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function entity_draft_show_redirects_to_tag_review_when_pending_tags_exist(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        $this->makePendingTagReview($doc);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertRedirect(route('source-documents.review.tags.show', $doc));
    }

    #[Test]
    public function entity_draft_show_passes_through_when_no_pending_tags(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        // No tag review records at all — middleware should pass through.

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }

    #[Test]
    public function entity_draft_show_passes_through_when_all_tags_decided(): void
    {
        // Tag review records exist but all have status != pending.
        // The middleware only blocks on actual pending decisions, not
        // on the page having been visited.
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'tag',
            'payload' => ['extracted_name' => 'Done', 'category' => 'tool'],
            'status' => 'confirmed',
        ]);
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'tag',
            'payload' => ['extracted_name' => 'Also done', 'category' => 'tool'],
            'status' => 'rejected',
        ]);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }

    #[Test]
    public function entity_draft_confirm_redirects_to_tag_review_when_pending_tags_exist(): void
    {
        // The redirect gate applies to all entity-draft routes,
        // not just show — including POST endpoints.
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        $this->makePendingTagReview($doc);

        $this->post(route('source-documents.review.confirm', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertRedirect(route('source-documents.review.tags.show', $doc));
    }

    #[Test]
    public function entity_draft_reject_redirects_to_tag_review_when_pending_tags_exist(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        $this->makePendingTagReview($doc);

        $this->post(route('source-documents.review.reject', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertRedirect(route('source-documents.review.tags.show', $doc));
    }

    #[Test]
    public function tag_review_show_is_not_gated_by_the_middleware(): void
    {
        // The tag review page itself must be reachable while pending
        // tags exist — otherwise users can never resolve the redirect
        // loop. Sanity check.
        $doc = $this->makeDocument();
        $this->makePendingTagReview($doc);

        $this->get(route('source-documents.review.tags.show', $doc))->assertOk();
    }

    #[Test]
    public function tag_review_action_endpoints_are_not_gated_by_the_middleware(): void
    {
        // Pending tag records by definition mean tag review is in
        // progress — the user must be able to act on them. The
        // middleware doesn't apply to these endpoints.
        $doc = $this->makeDocument();
        $record = $this->makePendingTagReview($doc);

        $this->postJson(route('source-documents.review.tags.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();
    }

    #[Test]
    public function middleware_only_considers_tag_records_pending_status(): void
    {
        // Sanity check that only tag-type records affect the gate.
        // A non-pending person review record should NOT block entity
        // drafts via this middleware. (A pending one would be caught
        // by RequirePersonReviewComplete — tested separately.)
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'person',
            'payload' => ['extracted_name' => 'Sarah'],
            'status' => 'confirmed',
        ]);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }

    #[Test]
    public function middleware_only_blocks_on_current_document_tag_records(): void
    {
        // A pending tag record on a different document should not
        // block entity drafts on this document.
        $thisDoc = $this->makeDocument();
        $otherDoc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($thisDoc);
        $this->makePendingTagReview($otherDoc, 'Tag from other doc');

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $thisDoc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }
}