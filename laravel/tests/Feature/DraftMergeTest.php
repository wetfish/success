<?php

namespace Tests\Feature;

use App\Models\AiUsageEvent;
use App\Models\ExtractedRecord;
use App\Models\Organization;
use App\Models\SourceDocument;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionProvider;
use App\Services\Extraction\FakeExtractionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end tests for the merge flow:
 *   - DraftMergeController::show (picker + editor)
 *   - DraftMergeController::synthesize (JSON endpoint)
 *   - DraftMergeController::store (execute merge)
 *
 * Plus the small touchpoint on DraftReviewController::show where the
 * "Merge into existing" affordance appears when candidates exist.
 *
 * The DuplicateDetector and DraftMerger have their own unit tests;
 * these tests focus on controller orchestration, request/response
 * shape, flash messages, and the synthesize endpoint's interaction
 * with the FakeExtractionProvider.
 */
class DraftMergeTest extends TestCase
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

    private function makeDraft(SourceDocument $doc, string $type, array $payload): ExtractedRecord
    {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => $type,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    private function bindFake(callable $configure): FakeExtractionProvider
    {
        $fake = new FakeExtractionProvider();
        $configure($fake);
        $this->app->instance(ExtractionProvider::class, $fake);
        return $fake;
    }

    /* Review-page integration ========================================= */

    #[Test]
    public function review_show_page_renders_merge_button_when_candidates_exist(): void
    {
        Organization::create(['name' => 'Lightning Labs', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Lightning Labs']);

        $response = $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]));

        $response->assertOk();
        $response->assertSee('Merge into existing');
    }

    #[Test]
    public function review_show_page_does_not_render_merge_button_without_candidates(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Unique Corp']);

        $response = $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]));

        $response->assertOk();
        $response->assertDontSee('Merge into existing');
    }

    /* show ============================================================ */

    #[Test]
    public function show_with_multiple_candidates_renders_picker(): void
    {
        Organization::create(['name' => 'Lightning Labs', 'type' => 'employer']);
        Organization::create(['name' => 'Lightning Rods Inc', 'type' => 'client']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Lightning']);

        $response = $this->get(route('source-documents.review.merge.show', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]));

        $response->assertOk();
        $response->assertSee('Lightning Labs');
        $response->assertSee('Lightning Rods Inc');
        // Picker doesn't render the per-field chooser buttons.
        $response->assertDontSee('Use existing');
    }

    #[Test]
    public function show_with_resolved_candidate_renders_editor(): void
    {
        $target = Organization::create([
            'name' => 'Lightning Labs',
            'type' => 'employer',
            'description' => 'Existing description text',
        ]);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Lightning Labs',
            'type' => 'employer',
            'description' => 'Draft description text',
        ]);

        $response = $this->get(route('source-documents.review.merge.show', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
            'candidate_id' => $target->id,
        ]));

        $response->assertOk();
        $response->assertSee('Existing description text');
        $response->assertSee('Draft description text');
        $response->assertSee('Use existing');
        $response->assertSee('Use draft');
    }

    #[Test]
    public function show_with_no_candidates_redirects_to_review_with_flash(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Unique Corp']);

        $response = $this->get(route('source-documents.review.merge.show', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]));

        $response->assertRedirect(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]));
        $response->assertSessionHas('status');
    }

    #[Test]
    public function show_for_non_pending_draft_redirects_with_flash(): void
    {
        Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);
        $draft->update(['status' => 'rejected']);

        $response = $this->get(route('source-documents.review.merge.show', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('status');
    }

    #[Test]
    public function show_with_unknown_candidate_id_falls_through_to_picker(): void
    {
        // Defending against stale tabs and hand-crafted candidate_ids:
        // an id that doesn't appear in the freshly-detected set should
        // never produce an editor view. We fall back to picker mode.
        Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);

        $response = $this->get(route('source-documents.review.merge.show', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
            'candidate_id' => 99999,
        ]));

        $response->assertOk();
        $response->assertDontSee('Use existing');
    }

    #[Test]
    public function show_404s_when_draft_does_not_belong_to_source_document(): void
    {
        $doc1 = $this->makeDocument();
        $doc2 = $this->makeDocument();
        $draft = $this->makeDraft($doc2, 'organization', ['name' => 'Acme']);

        $response = $this->get(route('source-documents.review.merge.show', [
            'sourceDocument' => $doc1->id,
            'draft' => $draft->id,
        ]));

        $response->assertNotFound();
    }

    /* synthesize ====================================================== */

    #[Test]
    public function synthesize_returns_combined_text_and_logs_success(): void
    {
        $this->bindFake(fn ($f) => $f->synthesisReturns('Combined description'));

        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);

        $response = $this->postJson(route('source-documents.review.merge.synthesize', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]), [
            'existing' => 'Existing text',
            'draft' => 'Draft text',
        ]);

        $response->assertOk();
        $response->assertJson(['synthesized' => 'Combined description']);

        $this->assertSame(
            1,
            AiUsageEvent::query()
                ->where('operation', 'synthesize')
                ->where('success', true)
                ->count(),
        );
    }

    #[Test]
    public function synthesize_returns_502_and_logs_failure_when_provider_throws(): void
    {
        $this->bindFake(fn ($f) => $f->throws(new ExtractionException('Simulated provider failure')));

        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);

        $response = $this->postJson(route('source-documents.review.merge.synthesize', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]), [
            'existing' => 'A',
            'draft' => 'B',
        ]);

        $response->assertStatus(502);
        $response->assertJsonStructure(['error']);

        $this->assertSame(
            1,
            AiUsageEvent::query()
                ->where('operation', 'synthesize')
                ->where('success', false)
                ->count(),
        );
    }

    #[Test]
    public function synthesize_returns_422_for_non_pending_draft(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);
        $draft->update(['status' => 'confirmed']);

        $response = $this->postJson(route('source-documents.review.merge.synthesize', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]), [
            'existing' => 'A',
            'draft' => 'B',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function synthesize_accepts_empty_strings_on_either_side(): void
    {
        // The 'present|string' rule lets either source be blank.
        // Merging a field where one side is empty is a legitimate
        // use case (e.g., the existing record never had a description
        // and the user wants to combine "(nothing)" with the draft).
        $this->bindFake(fn ($f) => $f->synthesisReturns('Result'));

        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);

        $response = $this->postJson(route('source-documents.review.merge.synthesize', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]), [
            'existing' => '',
            'draft' => 'Some draft text',
        ]);

        $response->assertOk();
    }

    /* store =========================================================== */

    #[Test]
    public function store_applies_chosen_fields_to_target_and_marks_draft_merged(): void
    {
        $target = Organization::create([
            'name' => 'Lightning Labs Inc',
            'type' => 'employer',
            'description' => 'Old',
        ]);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Lightning',
            'description' => 'New',
        ]);

        $response = $this->post(route('source-documents.review.merge.store', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]), [
            'candidate_id' => $target->id,
            'fields' => [
                'name' => 'Lightning Labs Inc',
                'description' => 'New',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Draft merged.');

        $target->refresh();
        $this->assertSame('Lightning Labs Inc', $target->name);
        $this->assertSame('New', $target->description);

        $draft->refresh();
        $this->assertSame('merged', $draft->status);
        $this->assertSame($target->id, $draft->match_record_id);
    }

    #[Test]
    public function store_rewrites_dependent_drafts(): void
    {
        $target = Organization::create(['name' => 'ACME Inc', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $orgDraft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);
        $positionDraft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Acme',
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ]);

        $this->post(route('source-documents.review.merge.store', [
            'sourceDocument' => $doc,
            'draft' => $orgDraft->id,
        ]), [
            'candidate_id' => $target->id,
            'fields' => [],
        ]);

        $positionDraft->refresh();
        $this->assertSame('ACME Inc', $positionDraft->payload['organization_name']);
    }

    #[Test]
    public function store_with_invalid_candidate_id_redirects_back_to_merge_with_flash(): void
    {
        Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);

        $response = $this->post(route('source-documents.review.merge.store', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]), [
            'candidate_id' => 99999,
            'fields' => [],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $draft->refresh();
        $this->assertSame('pending', $draft->status);
    }

    #[Test]
    public function store_with_missing_candidate_id_redirects_back_with_flash(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);

        $response = $this->post(route('source-documents.review.merge.store', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]), [
            'fields' => [],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $draft->refresh();
        $this->assertSame('pending', $draft->status);
    }

    #[Test]
    public function store_for_non_pending_draft_redirects_to_review_show(): void
    {
        $target = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);
        $draft->update(['status' => 'rejected']);

        $response = $this->post(route('source-documents.review.merge.store', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]), [
            'candidate_id' => $target->id,
            'fields' => [],
        ]);

        $response->assertRedirect(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $draft->id,
        ]));
        $response->assertSessionHas('status');
    }
}