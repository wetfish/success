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

    #[Test]
    public function review_page_renders_when_payload_contains_nested_array_fields(): void
    {
        // Regression: before the milestone 4.6 architecture shift, all
        // payload fields were scalar strings, and the review form's
        // default text-input branch worked for every key. After the
        // shift, entity drafts carry nested `tags`, `collaborators`,
        // and `links` arrays — Blade's `{{ $value }}` would call
        // htmlspecialchars() on an array and throw a TypeError,
        // making the page 500. The view now special-cases list types.
        $doc = $this->makeDocument();
        Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'Migration',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'tags' => [
                ['name' => 'Postgres', 'category' => 'tool'],
                ['name' => 'Python', 'category' => 'language'],
            ],
            'collaborators' => [
                ['name' => 'Sarah Chen', 'role' => 'Manager'],
            ],
            'links' => [
                ['url' => 'https://github.com/acme/migration', 'type' => 'github', 'title' => 'Source repo'],
            ],
        ]);

        $response = $this->get(route('source-documents.review.show', [
            'sourceDocument' => $doc,
            'draft' => $draft,
        ]));

        $response->assertOk();
        // Confirm the nested data actually rendered (not just that we
        // got a 200 from an empty page).
        $response->assertSee('Postgres');
        $response->assertSee('Sarah Chen');
        $response->assertSee('https://github.com/acme/migration', escape: false);
    }

    #[Test]
    public function nested_array_payload_fields_survive_form_submit_through_array_merge(): void
    {
        // The view renders nested arrays read-only — no <input> tags
        // for tags/collaborators/links. The controller's array_merge
        // is supposed to preserve those existing payload values when
        // the form doesn't supply them. This test asserts that
        // contract: confirming the draft via the form should still
        // materialize the nested tag attachment.
        $doc = $this->makeDocument();
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'Migration',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'tags' => [
                ['name' => 'Postgres', 'category' => 'tool'],
            ],
        ]);

        // Form submits only the scalar fields — no `tags` input exists
        // in the rendered HTML.
        $this->post(
            route('source-documents.review.confirm', [
                'sourceDocument' => $doc,
                'draft' => $draft,
            ]),
            [
                'organization_name' => 'Acme',
                'name' => 'Migration',
                'visibility' => 'internal',
                'contribution_level' => 'core',
            ]
        );

        $draft->refresh();
        // Payload still has the tags after array_merge.
        $this->assertCount(1, $draft->payload['tags']);
        $this->assertSame('Postgres', $draft->payload['tags'][0]['name']);

        // And the materialized project picked up the tag pivot row.
        $project = \App\Models\Project::first();
        $this->assertNotNull($project);
        $this->assertCount(1, $project->tags);
        $this->assertSame('Postgres', $project->tags->first()->name);
        $this->assertSame('tool', $project->tags->first()->category);
    }
}