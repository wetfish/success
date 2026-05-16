<?php

namespace Tests\Feature;

use App\Models\ExtractedRecord;
use App\Models\Link;
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
        // table but have a different action set (accept/reject/alias
        // for tags, accept/reject for people) and use separate review
        // UIs. Including them in this queue would make the user
        // navigate to records the existing Confirm action can't
        // dispatch — DraftConfirmer has no arm for 'tag' or 'link'.
        //
        // Note: person and link review records are set to 'confirmed'
        // so the wizard routing (which now checks for pending review
        // records) doesn't redirect to their review pages. The test's
        // intent is that review records don't appear in the entity-draft
        // queue — not about wizard step routing.
        $doc = $this->makeDocument();

        $entityDraft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
        ]);
        $this->makeDraft($doc, 'person', ['extracted_name' => 'Sarah Chen'], 'confirmed');
        $this->makeDraft($doc, 'link', ['url' => 'https://example.com', 'type' => 'website'], 'confirmed');

        // Index redirects to the first pending draft in the queue —
        // should be the entity draft, not any of the review records.
        $this->get(route('source-documents.review.index', $doc))
            ->assertRedirect(route('source-documents.review.show', [
                'sourceDocument' => $doc,
                'draft' => $entityDraft->id,
            ]));
    }

    #[Test]
    public function index_redirects_to_tag_review_when_pending_tag_records_exist(): void
    {
        // First wizard step takes precedence — the index routes to
        // tag review before entity drafts.
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'organization', ['name' => 'Acme', 'type' => 'employer']);
        $this->makeDraft($doc, 'tag', ['extracted_name' => 'Postgres', 'category' => 'tool']);

        $this->get(route('source-documents.review.index', $doc))
            ->assertRedirect(route('source-documents.review.tags.show', $doc));
    }

    #[Test]
    public function index_falls_through_to_entity_drafts_when_tag_records_all_decided(): void
    {
        // Decided tag records don't block — the wizard advances.
        $doc = $this->makeDocument();
        $entityDraft = $this->makeDraft($doc, 'organization', ['name' => 'Acme', 'type' => 'employer']);
        $this->makeDraft($doc, 'tag', ['extracted_name' => 'Done', 'category' => 'tool'], 'confirmed');

        $this->get(route('source-documents.review.index', $doc))
            ->assertRedirect(route('source-documents.review.show', [
                'sourceDocument' => $doc,
                'draft' => $entityDraft->id,
            ]));
    }

    #[Test]
    public function index_redirects_to_person_review_when_pending_person_records_exist(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'organization', ['name' => 'Acme', 'type' => 'employer']);
        $this->makeDraft($doc, 'person', ['extracted_name' => 'Sarah Chen']);

        $this->get(route('source-documents.review.index', $doc))
            ->assertRedirect(route('source-documents.review.people.show', $doc));
    }

    #[Test]
    public function index_redirects_to_link_review_when_pending_link_records_exist(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'organization', ['name' => 'Acme', 'type' => 'employer']);
        $this->makeDraft($doc, 'link', ['url' => 'https://example.com']);

        $this->get(route('source-documents.review.index', $doc))
            ->assertRedirect(route('source-documents.review.links.show', $doc));
    }

    #[Test]
    public function index_routes_tags_before_people_before_links(): void
    {
        // All three review types pending — tags take priority.
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'organization', ['name' => 'Acme', 'type' => 'employer']);
        $this->makeDraft($doc, 'tag', ['extracted_name' => 'PHP', 'category' => 'language']);
        $this->makeDraft($doc, 'person', ['extracted_name' => 'Sarah Chen']);
        $this->makeDraft($doc, 'link', ['url' => 'https://example.com']);

        $this->get(route('source-documents.review.index', $doc))
            ->assertRedirect(route('source-documents.review.tags.show', $doc));
    }

    #[Test]
    public function index_routes_people_before_links_when_tags_decided(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'organization', ['name' => 'Acme', 'type' => 'employer']);
        $this->makeDraft($doc, 'tag', ['extracted_name' => 'PHP', 'category' => 'language'], 'confirmed');
        $this->makeDraft($doc, 'person', ['extracted_name' => 'Sarah Chen']);
        $this->makeDraft($doc, 'link', ['url' => 'https://example.com']);

        $this->get(route('source-documents.review.index', $doc))
            ->assertRedirect(route('source-documents.review.people.show', $doc));
    }

    #[Test]
    public function confirm_skips_rejected_link_review_records_during_attachment(): void
    {
        $doc = $this->makeDocument();
        $orgDraft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
            'links' => [
                ['url' => 'https://keep.com', 'type' => 'website'],
                ['url' => 'https://reject.com', 'type' => 'website'],
            ],
        ]);

        // Create link review records — one confirmed, one rejected.
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'link',
            'payload' => ['url' => 'https://keep.com', 'type' => 'website'],
            'status' => 'confirmed',
        ]);
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'link',
            'payload' => ['url' => 'https://reject.com', 'type' => 'website'],
            'status' => 'rejected',
        ]);

        $this->post(route('source-documents.review.confirm', [
            'sourceDocument' => $doc,
            'draft' => $orgDraft->id,
        ]));

        $org = Organization::where('name', 'Acme')->first();
        $this->assertNotNull($org);

        $links = $org->links;
        $this->assertCount(1, $links);
        $this->assertSame('https://keep.com', $links->first()->url);
    }

    #[Test]
    public function confirm_uses_review_record_payload_for_edited_link_fields(): void
    {
        $doc = $this->makeDocument();
        $orgDraft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
            'links' => [
                ['url' => 'https://example.com', 'type' => 'website'],
            ],
        ]);

        // Review record has edited title and type.
        ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'link',
            'payload' => [
                'url' => 'https://example.com',
                'type' => 'careers',
                'title' => 'Acme Careers Page',
                'description' => 'Open positions at Acme',
            ],
            'status' => 'confirmed',
        ]);

        $this->post(route('source-documents.review.confirm', [
            'sourceDocument' => $doc,
            'draft' => $orgDraft->id,
        ]));

        $org = Organization::where('name', 'Acme')->first();
        $link = $org->links->first();
        $this->assertSame('careers', $link->type);
        $this->assertSame('Acme Careers Page', $link->title);
        $this->assertSame('Open positions at Acme', $link->description);
    }
}