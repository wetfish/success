<?php

namespace Tests\Feature;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the RequirePersonReviewComplete middleware.
 *
 * Symmetric with RequireTagReviewCompleteTest. The middleware gates
 * entity-draft routes when pending person review records exist.
 */
class RequirePersonReviewCompleteTest extends TestCase
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

    private function makePendingPersonReview(SourceDocument $doc, string $name = 'Sarah Chen'): ExtractedRecord
    {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'person',
            'payload' => ['extracted_name' => $name],
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function entity_draft_show_redirects_to_person_review_when_pending_people_exist(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        $this->makePendingPersonReview($doc);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertRedirect(route('source-documents.review.people.show', $doc));
    }

    #[Test]
    public function entity_draft_show_passes_through_when_no_pending_people(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }

    #[Test]
    public function entity_draft_show_passes_through_when_all_people_decided(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'person',
            'payload' => ['extracted_name' => 'Done'],
            'status' => 'confirmed',
        ]);
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'person',
            'payload' => ['extracted_name' => 'Also done'],
            'status' => 'rejected',
        ]);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }

    #[Test]
    public function entity_draft_confirm_redirects_when_pending_people_exist(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        $this->makePendingPersonReview($doc);

        $this->post(route('source-documents.review.confirm', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertRedirect(route('source-documents.review.people.show', $doc));
    }

    #[Test]
    public function person_review_page_is_not_gated_by_the_middleware(): void
    {
        $doc = $this->makeDocument();
        $this->makePendingPersonReview($doc);

        $this->get(route('source-documents.review.people.show', $doc))->assertOk();
    }

    #[Test]
    public function person_review_action_endpoints_are_not_gated(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makePendingPersonReview($doc);

        $this->postJson(route('source-documents.review.people.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();
    }

    #[Test]
    public function middleware_only_considers_person_records(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        // A non-pending link review record should NOT block entity
        // drafts via this middleware. (A pending one would be caught
        // by RequireLinkReviewComplete — tested separately.)
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'link',
            'payload' => ['url' => 'https://example.com'],
            'status' => 'confirmed',
        ]);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }

    #[Test]
    public function middleware_only_blocks_on_current_document(): void
    {
        $thisDoc = $this->makeDocument();
        $otherDoc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($thisDoc);
        $this->makePendingPersonReview($otherDoc);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $thisDoc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }
}