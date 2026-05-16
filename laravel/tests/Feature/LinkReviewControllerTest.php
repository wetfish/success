<?php

namespace Tests\Feature;

use App\Models\ExtractedRecord;
use App\Models\Link;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the link review wizard step controller.
 *
 * Covers:
 *   - The show page (rendering, mentions, empty redirect)
 *   - accept / reject action endpoints (state transitions, JSON shape)
 *   - update action (payload field editing)
 *   - Cross-document scoping
 */
class LinkReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeDocument(?string $title = null): SourceDocument
    {
        return SourceDocument::create([
            'title' => $title ?? 'Test',
            'kind' => 'other',
            'file_type' => 'text',
            'body' => 'Test body',
        ]);
    }

    private function makeLinkReviewRecord(
        SourceDocument $doc,
        string $url,
        string $status = 'pending',
        array $extraPayload = [],
    ): ExtractedRecord {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'link',
            'payload' => array_merge(['url' => $url], $extraPayload),
            'status' => $status,
        ]);
    }

    private function makeEntityDraft(SourceDocument $doc, string $type, array $payload): ExtractedRecord
    {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => $type,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // show: page rendering
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function show_renders_all_link_review_records(): void
    {
        $doc = $this->makeDocument();
        $this->makeLinkReviewRecord($doc, 'https://example.com');
        $this->makeLinkReviewRecord($doc, 'https://github.com/test');

        $response = $this->get(route('source-documents.review.links.show', $doc));

        $response->assertOk();
        $response->assertSee('https://example.com');
        $response->assertSee('https://github.com/test');
    }

    #[Test]
    public function show_includes_already_decided_records(): void
    {
        $doc = $this->makeDocument();
        $this->makeLinkReviewRecord($doc, 'https://pending.com', 'pending');
        $this->makeLinkReviewRecord($doc, 'https://confirmed.com', 'confirmed');
        $this->makeLinkReviewRecord($doc, 'https://rejected.com', 'rejected');

        $response = $this->get(route('source-documents.review.links.show', $doc));

        $response->assertOk();
        $response->assertSee('https://pending.com');
        $response->assertSee('https://confirmed.com');
        $response->assertSee('https://rejected.com');
    }

    #[Test]
    public function show_redirects_when_document_has_no_link_review_records(): void
    {
        $doc = $this->makeDocument();

        $this->get(route('source-documents.review.links.show', $doc))
            ->assertRedirect(route('source-documents.review.index', $doc));
    }

    #[Test]
    public function show_includes_mentions_context_from_entity_drafts(): void
    {
        $doc = $this->makeDocument();
        $this->makeLinkReviewRecord($doc, 'https://example.com');
        $this->makeEntityDraft($doc, 'organization', [
            'name' => 'Acme Corp',
            'links' => [['url' => 'https://example.com', 'type' => 'website']],
        ]);

        $response = $this->get(route('source-documents.review.links.show', $doc));

        $response->assertOk();
        $response->assertSee('Acme Corp');
    }

    // ────────────────────────────────────────────────────────────
    // accept action
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function accept_sets_status_to_confirmed(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeLinkReviewRecord($doc, 'https://example.com');

        $response = $this->postJson(route('source-documents.review.links.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]));

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $record->refresh();
        $this->assertSame('confirmed', $record->status);
    }

    #[Test]
    public function accept_is_idempotent(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeLinkReviewRecord($doc, 'https://example.com', 'confirmed');

        $this->postJson(route('source-documents.review.links.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $record->refresh();
        $this->assertSame('confirmed', $record->status);
    }

    #[Test]
    public function accept_404s_for_cross_document_record(): void
    {
        $doc = $this->makeDocument();
        $otherDoc = $this->makeDocument('Other');
        $record = $this->makeLinkReviewRecord($otherDoc, 'https://example.com');

        $this->postJson(route('source-documents.review.links.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertStatus(404);
    }

    // ────────────────────────────────────────────────────────────
    // reject action
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function reject_sets_status_to_rejected(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeLinkReviewRecord($doc, 'https://example.com');

        $this->postJson(route('source-documents.review.links.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $record->refresh();
        $this->assertSame('rejected', $record->status);
    }

    #[Test]
    public function reject_404s_for_cross_document_record(): void
    {
        $doc = $this->makeDocument();
        $otherDoc = $this->makeDocument('Other');
        $record = $this->makeLinkReviewRecord($otherDoc, 'https://example.com');

        $this->postJson(route('source-documents.review.links.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertStatus(404);
    }

    // ────────────────────────────────────────────────────────────
    // update action
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function update_modifies_payload_fields(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeLinkReviewRecord($doc, 'https://example.com', 'pending', [
            'type' => 'website',
            'title' => 'Original Title',
        ]);

        $response = $this->postJson(route('source-documents.review.links.update', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), [
            'title' => 'Updated Title',
            'type' => 'blog_post',
            'description' => 'A blog post about testing',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $response->assertJsonStructure(['ok', 'payload']);

        $record->refresh();
        $this->assertSame('Updated Title', $record->payload['title']);
        $this->assertSame('blog_post', $record->payload['type']);
        $this->assertSame('A blog post about testing', $record->payload['description']);
        // URL should be unchanged — we didn't send it.
        $this->assertSame('https://example.com', $record->payload['url']);
    }

    #[Test]
    public function update_handles_url_change(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeLinkReviewRecord($doc, 'https://old.com');

        $this->postJson(route('source-documents.review.links.update', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['url' => 'https://new.com'])->assertOk();

        $record->refresh();
        $this->assertSame('https://new.com', $record->payload['url']);
    }

    #[Test]
    public function update_handles_is_personal_appearance_toggle(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeLinkReviewRecord($doc, 'https://example.com');

        $this->postJson(route('source-documents.review.links.update', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['is_personal_appearance' => true])->assertOk();

        $record->refresh();
        $this->assertTrue($record->payload['is_personal_appearance']);
    }

    #[Test]
    public function update_ignores_invalid_link_type(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeLinkReviewRecord($doc, 'https://example.com', 'pending', [
            'type' => 'website',
        ]);

        $this->postJson(route('source-documents.review.links.update', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['type' => 'not_a_real_type'])->assertOk();

        $record->refresh();
        // Type should be unchanged since the value wasn't in Link::TYPES.
        $this->assertSame('website', $record->payload['type']);
    }

    #[Test]
    public function update_clears_field_when_empty_string_sent(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeLinkReviewRecord($doc, 'https://example.com', 'pending', [
            'title' => 'Has a title',
        ]);

        $this->postJson(route('source-documents.review.links.update', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['title' => ''])->assertOk();

        $record->refresh();
        $this->assertNull($record->payload['title']);
    }

    #[Test]
    public function update_404s_for_cross_document_record(): void
    {
        $doc = $this->makeDocument();
        $otherDoc = $this->makeDocument('Other');
        $record = $this->makeLinkReviewRecord($otherDoc, 'https://example.com');

        $this->postJson(route('source-documents.review.links.update', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['title' => 'Hacked'])->assertStatus(404);
    }

    // ────────────────────────────────────────────────────────────
    // JSON response shape contract
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function action_responses_always_return_json(): void
    {
        $doc = $this->makeDocument();
        $otherDoc = $this->makeDocument('Other');
        $record = $this->makeLinkReviewRecord($otherDoc, 'https://example.com');

        $response = $this->postJson(route('source-documents.review.links.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]));

        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonStructure(['error']);
    }
}