<?php

namespace Tests\Feature;

use App\Models\ExtractedRecord;
use App\Models\Organization;
use App\Models\Position;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the draft confirmation HTTP flow. The
 * DraftConfirmerTest exercises the service in isolation; these
 * tests focus on the controller wiring — auth (none yet), routing,
 * redirects, and flash messages.
 */
class DraftConfirmationTest extends TestCase
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

    private function makeDraft(SourceDocument $doc, string $type, array $payload, string $status = 'pending'): ExtractedRecord
    {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => $type,
            'payload' => $payload,
            'status' => $status,
        ]);
    }

    #[Test]
    public function confirming_an_org_draft_creates_the_org_and_redirects_to_next_draft(): void
    {
        $doc = $this->makeDocument();
        $orgDraft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
        ]);
        $positionDraft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Acme',
            'title' => 'Engineer',
        ]);

        $this->post(route('source-documents.review.confirm', [
            'sourceDocument' => $doc,
            'draft' => $orgDraft,
        ]))->assertRedirect(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $positionDraft,
        ]));

        $this->assertSame(1, Organization::count());
        $orgDraft->refresh();
        $this->assertSame('confirmed', $orgDraft->status);
    }

    #[Test]
    public function failed_confirmation_stays_on_current_draft_with_error_message(): void
    {
        $doc = $this->makeDocument();
        $positionDraft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Nonexistent',
            'title' => 'Engineer',
        ]);

        $this->post(route('source-documents.review.confirm', [
            'sourceDocument' => $doc,
            'draft' => $positionDraft,
        ]))
            ->assertRedirect(route('source-documents.review.show', [
                'sourceDocument' => $doc,
                'draft' => $positionDraft,
            ]))
            ->assertSessionHas('status'); // some flash message

        $this->assertSame(0, Position::count());
        $positionDraft->refresh();
        $this->assertSame('pending', $positionDraft->status);
    }

    #[Test]
    public function confirming_a_draft_belonging_to_a_different_document_404s(): void
    {
        $doc1 = $this->makeDocument();
        $doc2 = $this->makeDocument();
        $draft = $this->makeDraft($doc1, 'organization', ['name' => 'Acme']);

        $this->post(route('source-documents.review.confirm', [
            'sourceDocument' => $doc2,
            'draft' => $draft,
        ]))->assertNotFound();
    }

    #[Test]
    public function confirming_an_already_confirmed_draft_shows_error(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme'], status: 'confirmed');

        $this->post(route('source-documents.review.confirm', [
            'sourceDocument' => $doc,
            'draft' => $draft,
        ]))->assertSessionHas('status');

        // Status unchanged, no new org created.
        $this->assertSame(0, Organization::count());
    }

    #[Test]
    public function confirming_at_end_of_queue_stays_on_current_draft(): void
    {
        $doc = $this->makeDocument();
        $orgDraft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
        ]);

        // Only one draft — confirming should stay on it.
        $this->post(route('source-documents.review.confirm', [
            'sourceDocument' => $doc,
            'draft' => $orgDraft,
        ]))->assertRedirect(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $orgDraft,
        ]));

        $orgDraft->refresh();
        $this->assertSame('confirmed', $orgDraft->status);
    }

    #[Test]
    public function review_queue_excludes_tag_person_and_link_review_records(): void
    {
        // The existing review queue is for entity drafts only. The
        // tag/person/link review records produced by
        // ReviewRecordExtractor live in the same extracted_records
        // table but have a different action set (confirm/merge/alias
        // against the catalog) and will get a separate review UI.
        // Until then, including them in this queue would make the
        // user navigate to records the existing Confirm action can't
        // dispatch — DraftConfirmer has no arm for 'tag' or 'link'.
        $doc = $this->makeDocument();

        $entityDraft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
        ]);
        $this->makeDraft($doc, 'tag', ['extracted_name' => 'Postgres', 'category' => 'tool']);
        $this->makeDraft($doc, 'person', ['extracted_name' => 'Sarah Chen']);
        $this->makeDraft($doc, 'link', ['url' => 'https://example.com', 'type' => 'website']);

        // Index redirects to the first pending draft in the queue —
        // should be the entity draft, not any of the review records.
        $this->get(route('source-documents.review.index', $doc))
            ->assertRedirect(route('source-documents.review.show', [
                'sourceDocument' => $doc,
                'draft' => $entityDraft->id,
            ]));
    }
}