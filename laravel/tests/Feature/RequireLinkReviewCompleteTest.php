<?php

namespace Tests\Feature;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the RequireLinkReviewComplete middleware.
 *
 * Symmetric with RequireTagReviewCompleteTest and
 * RequirePersonReviewCompleteTest. The middleware gates entity-draft
 * routes when pending link review records exist.
 */
class RequireLinkReviewCompleteTest extends TestCase
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

    private function makePendingLinkReview(SourceDocument $doc, string $url = 'https://example.com'): ExtractedRecord
    {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'link',
            'payload' => ['url' => $url],
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function entity_draft_show_redirects_to_link_review_when_pending_links_exist(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        $this->makePendingLinkReview($doc);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertRedirect(route('source-documents.review.links.show', $doc));
    }

    #[Test]
    public function entity_draft_show_passes_through_when_no_pending_links(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }

    #[Test]
    public function entity_draft_show_passes_through_when_all_links_decided(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'link',
            'payload' => ['url' => 'https://confirmed.com'],
            'status' => 'confirmed',
        ]);
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'link',
            'payload' => ['url' => 'https://rejected.com'],
            'status' => 'rejected',
        ]);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }

    #[Test]
    public function entity_draft_confirm_redirects_when_pending_links_exist(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        $this->makePendingLinkReview($doc);

        $this->post(route('source-documents.review.confirm', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]))->assertRedirect(route('source-documents.review.links.show', $doc));
    }

    #[Test]
    public function link_review_page_is_not_gated_by_the_middleware(): void
    {
        $doc = $this->makeDocument();
        $this->makePendingLinkReview($doc);

        $this->get(route('source-documents.review.links.show', $doc))->assertOk();
    }

    #[Test]
    public function link_review_action_endpoints_are_not_gated(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makePendingLinkReview($doc);

        $this->postJson(route('source-documents.review.links.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();
    }

    #[Test]
    public function middleware_only_considers_link_records(): void
    {
        $doc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($doc);
        // A pending tag review record should NOT trigger the link middleware.
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'tag',
            'payload' => ['extracted_name' => 'Python', 'category' => 'language'],
            'status' => 'pending',
        ]);

        // Tag middleware will redirect to tag review, not link review.
        // But the link middleware itself should pass through.
        $response = $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $entityDraft->id,
        ]));

        // Redirects to tag review (tag middleware fires first), not link review.
        $response->assertRedirect(route('source-documents.review.tags.show', $doc));
    }

    #[Test]
    public function middleware_only_blocks_on_current_document(): void
    {
        $thisDoc = $this->makeDocument();
        $otherDoc = $this->makeDocument();
        $entityDraft = $this->makeEntityDraft($thisDoc);
        $this->makePendingLinkReview($otherDoc);

        $this->get(route('source-documents.review.show', [
            'sourceDocument' => $thisDoc,
            'draft' => $entityDraft->id,
        ]))->assertOk();
    }
}