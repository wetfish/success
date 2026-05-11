<?php

namespace Tests\Feature;

use App\Models\ExtractedRecord;
use App\Models\Organization;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the form-merge behavior of the confirmation flow.
 * The DraftReviewController's confirm() action merges form input into
 * the draft's payload before passing it to the DraftConfirmer. This
 * lets the user fill in fields the AI omitted (e.g., a missing date
 * or description) without needing a separate edit step.
 *
 * The existing DraftConfirmerTest covers the service-level logic;
 * these tests focus on the form → payload → confirmation pipeline.
 */
class DraftFormConfirmationTest extends TestCase
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

    #[Test]
    public function form_input_merges_into_payload_before_confirmation(): void
    {
        // Draft has a partial payload — missing description (a required
        // field). User submits the form with description filled in.
        // The confirmation should succeed and the description should
        // end up on the real organization.
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
            // No description.
        ]);

        $this->post(
            route('source-documents.review.confirm', [
                'sourceDocument' => $doc,
                'draft' => $draft,
            ]),
            [
                'name' => 'Acme',
                'type' => 'employer',
                'description' => 'A great company',
            ]
        );

        $org = Organization::first();
        $this->assertSame('A great company', $org->description);

        // The draft's payload also reflects the merged form data.
        $draft->refresh();
        $this->assertSame('A great company', $draft->payload['description']);
    }

    #[Test]
    public function form_edits_persist_even_when_confirmation_fails(): void
    {
        // Draft references a nonexistent organization. The user submits
        // the form with the position title corrected. Confirmation
        // fails (org doesn't exist), but the user's edits should
        // persist so they don't have to retype them after confirming
        // the org draft first.
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Nonexistent',
            'title' => 'Old Title',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ]);

        $this->post(
            route('source-documents.review.confirm', [
                'sourceDocument' => $doc,
                'draft' => $draft,
            ]),
            [
                'organization_name' => 'Nonexistent',
                'title' => 'Corrected Title',  // user fix
                'employment_type' => 'full_time',
                'start_date' => '2020-01-01',
                'location_arrangement' => 'remote',
            ]
        )->assertSessionHas('status');

        $draft->refresh();
        $this->assertSame('pending', $draft->status);
        $this->assertSame('Corrected Title', $draft->payload['title']);
    }

    #[Test]
    public function empty_form_inputs_clear_payload_values(): void
    {
        // The user deliberately clears the website field. The
        // confirmation should succeed with website set to null on
        // the real organization.
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
            'website' => 'https://old-url.example',
        ]);

        $this->post(
            route('source-documents.review.confirm', [
                'sourceDocument' => $doc,
                'draft' => $draft,
            ]),
            [
                'name' => 'Acme',
                'type' => 'employer',
                'website' => '',  // explicit clear
            ]
        );

        $org = Organization::first();
        $this->assertNull($org->website);
    }

    #[Test]
    public function fields_outside_the_schema_are_ignored(): void
    {
        // Someone POSTs extra fields that aren't in the form schema.
        // Those should be dropped and not poison the payload.
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
        ]);

        $this->post(
            route('source-documents.review.confirm', [
                'sourceDocument' => $doc,
                'draft' => $draft,
            ]),
            [
                'name' => 'Acme',
                'type' => 'employer',
                'unknown_field' => 'should be dropped',
                'another_extra' => 'also dropped',
            ]
        );

        $draft->refresh();
        $this->assertArrayNotHasKey('unknown_field', $draft->payload);
        $this->assertArrayNotHasKey('another_extra', $draft->payload);
    }
}