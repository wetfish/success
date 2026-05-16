<?php

namespace Tests\Feature;

use App\Models\ExtractedRecord;
use App\Models\Person;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the person review wizard step controller.
 *
 * Covers:
 *   - The show page (rendering, mentions, empty redirect)
 *   - accept / reject action endpoints (state transitions,
 *     idempotency, catalog mutations, JSON shape)
 *   - Cross-document scoping
 *
 * Symmetric with TagReviewControllerTest minus alias tests — people
 * don't have aliases.
 */
class PersonReviewControllerTest extends TestCase
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

    private function makePersonReviewRecord(
        SourceDocument $doc,
        string $extractedName,
        string $status = 'pending',
        ?int $matchRecordId = null,
        bool $catalogCreatedByReview = false,
    ): ExtractedRecord {
        $payload = ['extracted_name' => $extractedName];
        if ($catalogCreatedByReview) {
            $payload['catalog_person_created_by_review'] = true;
        }
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'person',
            'payload' => $payload,
            'status' => $status,
            'match_record_type' => $matchRecordId ? 'person' : null,
            'match_record_id' => $matchRecordId,
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
    public function show_renders_all_person_review_records(): void
    {
        $doc = $this->makeDocument();
        $this->makePersonReviewRecord($doc, 'Sarah Chen');
        $this->makePersonReviewRecord($doc, 'John Smith');

        $response = $this->get(route('source-documents.review.people.show', $doc));

        $response->assertOk();
        $response->assertSee('Sarah Chen');
        $response->assertSee('John Smith');
    }

    #[Test]
    public function show_includes_already_decided_records_alongside_pending(): void
    {
        $doc = $this->makeDocument();
        $existing = Person::create(['name' => 'Sarah Chen']);
        $this->makePersonReviewRecord($doc, 'John Smith', 'pending');
        $this->makePersonReviewRecord($doc, 'Sarah Chen', 'confirmed', $existing->id);
        $this->makePersonReviewRecord($doc, 'Rejected Person', 'rejected');

        $response = $this->get(route('source-documents.review.people.show', $doc));

        $response->assertOk();
        $response->assertSee('John Smith');
        $response->assertSee('Sarah Chen');
        $response->assertSee('Rejected Person');
    }

    #[Test]
    public function show_redirects_when_document_has_no_person_review_records(): void
    {
        $doc = $this->makeDocument();

        $this->get(route('source-documents.review.people.show', $doc))
            ->assertRedirect(route('source-documents.review.index', $doc));
    }

    #[Test]
    public function show_includes_mentions_context_from_entity_drafts(): void
    {
        $doc = $this->makeDocument();
        $this->makePersonReviewRecord($doc, 'Sarah Chen');
        $this->makeEntityDraft($doc, 'position', [
            'title' => 'Senior Engineer',
            'collaborators' => [['name' => 'Sarah Chen', 'role' => 'Manager']],
        ]);

        $response = $this->get(route('source-documents.review.people.show', $doc));

        $response->assertOk();
        $response->assertSee('Senior Engineer');
        $response->assertSee('Manager');
    }

    // ────────────────────────────────────────────────────────────
    // accept action
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function accept_creates_catalog_person_and_confirms_record(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makePersonReviewRecord($doc, 'Sarah Chen');

        $response = $this->postJson(route('source-documents.review.people.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]));

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $response->assertJsonStructure(['ok', 'catalog_person_name']);

        $record->refresh();
        $this->assertSame('confirmed', $record->status);
        $this->assertSame('person', $record->match_record_type);
        $this->assertNotNull($record->match_record_id);
        $this->assertTrue($record->payload['catalog_person_created_by_review']);

        $newPerson = Person::find($record->match_record_id);
        $this->assertSame('Sarah Chen', $newPerson->name);
    }

    #[Test]
    public function accept_attaches_to_existing_catalog_person_without_creating_duplicate(): void
    {
        $doc = $this->makeDocument();
        $existing = Person::create(['name' => 'Sarah Chen']);
        $record = $this->makePersonReviewRecord($doc, 'Sarah Chen');

        $this->postJson(route('source-documents.review.people.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $record->refresh();
        $this->assertSame($existing->id, $record->match_record_id);
        $this->assertArrayNotHasKey('catalog_person_created_by_review', $record->payload);
        $this->assertSame(1, Person::where('name', 'Sarah Chen')->count());
    }

    #[Test]
    public function accept_case_insensitive_match_to_existing_person(): void
    {
        $doc = $this->makeDocument();
        $existing = Person::create(['name' => 'Sarah Chen']);
        $record = $this->makePersonReviewRecord($doc, 'sarah chen');

        $this->postJson(route('source-documents.review.people.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $record->refresh();
        $this->assertSame($existing->id, $record->match_record_id);
    }

    #[Test]
    public function accept_404s_for_cross_document_record(): void
    {
        $doc = $this->makeDocument();
        $otherDoc = $this->makeDocument('Other');
        $record = $this->makePersonReviewRecord($otherDoc, 'Sarah Chen');

        $this->postJson(route('source-documents.review.people.accept', [
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
        $record = $this->makePersonReviewRecord($doc, 'Sarah Chen');

        $this->postJson(route('source-documents.review.people.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $record->refresh();
        $this->assertSame('rejected', $record->status);
        $this->assertNull($record->match_record_id);
    }

    #[Test]
    public function reject_deletes_catalog_person_when_review_created_it(): void
    {
        $doc = $this->makeDocument();
        $created = Person::create(['name' => 'Sarah Chen']);
        $record = $this->makePersonReviewRecord(
            $doc, 'Sarah Chen', 'confirmed', $created->id, catalogCreatedByReview: true,
        );

        $this->postJson(route('source-documents.review.people.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        // Person uses SoftDeletes, so it should be soft-deleted.
        $this->assertSoftDeleted('people', ['id' => $created->id]);
    }

    #[Test]
    public function reject_leaves_catalog_person_when_review_did_not_create_it(): void
    {
        $doc = $this->makeDocument();
        $existing = Person::create(['name' => 'Sarah Chen']);
        $record = $this->makePersonReviewRecord(
            $doc, 'Sarah Chen', 'confirmed', $existing->id, catalogCreatedByReview: false,
        );

        $this->postJson(route('source-documents.review.people.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $this->assertNotNull(Person::find($existing->id));
    }

    #[Test]
    public function reject_404s_for_cross_document_record(): void
    {
        $doc = $this->makeDocument();
        $otherDoc = $this->makeDocument('Other');
        $record = $this->makePersonReviewRecord($otherDoc, 'Sarah Chen');

        $this->postJson(route('source-documents.review.people.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertStatus(404);
    }

    // ────────────────────────────────────────────────────────────
    // JSON response shape contract
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function action_responses_always_return_json(): void
    {
        $doc = $this->makeDocument();
        $otherDoc = $this->makeDocument('Other');
        $record = $this->makePersonReviewRecord($otherDoc, 'Sarah Chen');

        $response = $this->postJson(route('source-documents.review.people.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]));

        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonStructure(['error']);
    }
}