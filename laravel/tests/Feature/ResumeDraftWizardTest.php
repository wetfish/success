<?php

namespace Tests\Feature;

use App\Models\Accomplishment;
use App\Models\JobListing;
use App\Models\JobListingRequirement;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use App\Models\ResumeDraft;
use App\Models\ResumeSelection;
use App\Models\SourceDocument;
use App\Services\Resume\DraftResult;
use App\Services\Resume\ResumeAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the resume draft wizard HTTP flow. Covers
 * routing, redirects, validation, and state transitions across
 * all screens and the editing phase. Does NOT test the AI service
 * directly — those calls are mocked or bypassed by creating
 * records directly.
 */
class ResumeDraftWizardTest extends TestCase
{
    use RefreshDatabase;

    private function makeListingWithRequirements(int $requirementCount = 3): array
    {
        $org = Organization::create([
            'name' => 'Test Corp',
            'type' => 'employer',
        ]);

        $listing = JobListing::create([
            'organization_id' => $org->id,
            'role_title' => 'Staff Engineer',
            'body' => 'Test listing body with requirements.',
            'status' => 'active',
        ]);

        $requirements = [];
        for ($i = 0; $i < $requirementCount; $i++) {
            $requirements[] = JobListingRequirement::create([
                'job_listing_id' => $listing->id,
                'category' => 'technical_skill',
                'section' => 'required',
                'title' => "Requirement {$i}",
                'display_order' => $i,
            ]);
        }

        return [$listing, $requirements, $org];
    }

    private function makeDraftWithSelections(
        JobListing $listing,
        array $requirements,
    ): ResumeDraft {
        $draft = ResumeDraft::create([
            'job_listing_id' => $listing->id,
            'strategy_summary_generated' => 'AI strategy',
            'strategy_summary' => 'AI strategy',
            'status' => 'selecting',
        ]);

        $org = $listing->organization;
        $position = Position::create([
            'organization_id' => $org->id,
            'title' => 'Developer',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ]);

        foreach ($requirements as $req) {
            ResumeSelection::create([
                'resume_draft_id' => $draft->id,
                'job_listing_requirement_id' => $req->id,
                'selectable_type' => Position::class,
                'selectable_id' => $position->id,
                'selected' => true,
                'ai_reasoning' => 'Test reasoning',
                'display_order' => 0,
            ]);
        }

        return $draft;
    }

    /**
     * Bind a mock ResumeAiService that returns a canned DraftResult.
     * Used by confirm tests since confirm now triggers generation.
     */
    private function mockGenerateDraft(string $markdown = '# Test Resume'): void
    {
        $mock = $this->mock(ResumeAiService::class);
        $mock->shouldReceive('generateDraft')
            ->andReturn(new DraftResult(
                markdown: $markdown,
                inputTokens: 100,
                outputTokens: 200,
                costCents: 1,
                model: 'test-model',
            ));
    }

    /**
     * Create a draft already in `editing` status with generated content.
     */
    private function makeDraftInEditing(
        JobListing $listing,
        array $requirements,
    ): ResumeDraft {
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        $decisions = [];
        foreach ($requirements as $req) {
            $decisions[$req->id] = 'accepted';
        }

        $draft->update([
            'requirement_decisions' => $decisions,
            'generated_content' => '# AI Generated Resume',
            'user_content' => '# AI Generated Resume',
            'status' => 'editing',
        ]);

        return $draft;
    }

    // -----------------------------------------------------------------
    // Screen 1: Triage
    // -----------------------------------------------------------------

    #[Test]
    public function show_renders_triage_page(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements();
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        $response = $this->get(route('resume-drafts.show', $draft));

        $response->assertOk();
        $response->assertViewIs('resume-drafts.triage');
        $response->assertSee('Requirement 0');
        $response->assertSee('Requirement 1');
        $response->assertSee('Requirement 2');
    }

    #[Test]
    public function decide_requirement_accepts(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        $response = $this->postJson(
            route('resume-drafts.decide-requirement', [$draft, $requirements[0]]),
            ['decision' => 'accepted'],
        );

        $response->assertJson(['ok' => true, 'decision' => 'accepted']);
        $draft->refresh();
        $this->assertEquals('accepted', $draft->requirement_decisions[$requirements[0]->id]);
    }

    #[Test]
    public function decide_requirement_rejects(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        $response = $this->postJson(
            route('resume-drafts.decide-requirement', [$draft, $requirements[0]]),
            ['decision' => 'rejected'],
        );

        $response->assertJson(['ok' => true, 'decision' => 'rejected']);
    }

    #[Test]
    public function decide_requirement_marks_duplicate(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(2);
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        // Accept the primary first.
        $this->postJson(
            route('resume-drafts.decide-requirement', [$draft, $requirements[0]]),
            ['decision' => 'accepted'],
        );

        // Mark the second as a duplicate of the first.
        $response = $this->postJson(
            route('resume-drafts.decide-requirement', [$draft, $requirements[1]]),
            ['decision' => 'duplicate', 'duplicate_of' => $requirements[0]->id],
        );

        $response->assertJson(['ok' => true, 'decision' => 'duplicate']);
        $draft->refresh();
        $this->assertEquals(
            ['duplicate_of' => $requirements[0]->id],
            $draft->requirement_decisions[$requirements[1]->id],
        );
    }

    #[Test]
    public function duplicate_requires_accepted_primary(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(2);
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        // Try to mark as duplicate without accepting the primary.
        $response = $this->postJson(
            route('resume-drafts.decide-requirement', [$draft, $requirements[1]]),
            ['decision' => 'duplicate', 'duplicate_of' => $requirements[0]->id],
        );

        $response->assertStatus(422);
    }

    #[Test]
    public function decide_rejects_cross_listing_requirement(): void
    {
        [$listing1, $requirements1] = $this->makeListingWithRequirements(1);
        [$listing2, $requirements2] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing1, $requirements1);

        $response = $this->postJson(
            route('resume-drafts.decide-requirement', [$draft, $requirements2[0]]),
            ['decision' => 'accepted'],
        );

        $response->assertStatus(404);
    }

    #[Test]
    public function update_strategy_saves(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements();
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        $response = $this->postJson(
            route('resume-drafts.update-strategy', $draft),
            ['strategy_summary' => 'My custom strategy'],
        );

        $response->assertJson(['ok' => true]);
        $this->assertEquals('My custom strategy', $draft->fresh()->strategy_summary);
    }

    #[Test]
    public function update_strategy_locked_after_confirm(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements();
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $draft->update(['status' => 'drafting']);

        $response = $this->postJson(
            route('resume-drafts.update-strategy', $draft),
            ['strategy_summary' => 'Trying to change'],
        );

        $response->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Screen 2: Per-requirement review
    // -----------------------------------------------------------------

    #[Test]
    public function show_requirement_renders_for_accepted(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $draft->update(['requirement_decisions' => [$requirements[0]->id => 'accepted']]);

        $response = $this->get(
            route('resume-drafts.requirement', [$draft, $requirements[0]]),
        );

        $response->assertOk();
        $response->assertViewIs('resume-drafts.requirement');
    }

    #[Test]
    public function show_requirement_redirects_for_rejected(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $draft->update(['requirement_decisions' => [$requirements[0]->id => 'rejected']]);

        $response = $this->get(
            route('resume-drafts.requirement', [$draft, $requirements[0]]),
        );

        $response->assertRedirect(route('resume-drafts.show', $draft));
    }

    #[Test]
    public function show_requirement_redirects_for_duplicate(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(2);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $draft->update(['requirement_decisions' => [
            $requirements[0]->id => 'accepted',
            $requirements[1]->id => ['duplicate_of' => $requirements[0]->id],
        ]]);

        $response = $this->get(
            route('resume-drafts.requirement', [$draft, $requirements[1]]),
        );

        $response->assertRedirect(route('resume-drafts.show', $draft));
    }

    #[Test]
    public function toggle_selection_flips_state(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $selection = $draft->selections()->first();

        $this->assertTrue($selection->selected);

        $response = $this->postJson(
            route('resume-drafts.toggle', [$draft, $selection]),
        );

        $response->assertJson(['ok' => true, 'selected' => false]);
        $this->assertFalse($selection->fresh()->selected);
    }

    #[Test]
    public function update_note_saves(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $selection = $draft->selections()->first();

        $response = $this->postJson(
            route('resume-drafts.update-note', [$draft, $selection]),
            ['user_relevance_note' => 'Very relevant because...'],
        );

        $response->assertJson(['ok' => true]);
        $this->assertEquals('Very relevant because...', $selection->fresh()->user_relevance_note);
    }

    #[Test]
    public function add_selection_creates_entry(): void
    {
        [$listing, $requirements, $org] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $project = Project::create([
            'organization_id' => $org->id,
            'name' => 'Test Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $response = $this->post(
            route('resume-drafts.add-selection', [$draft, $requirements[0]]),
            ['selectable_type' => 'project', 'selectable_id' => $project->id],
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('resume_selections', [
            'resume_draft_id' => $draft->id,
            'job_listing_requirement_id' => $requirements[0]->id,
            'selectable_type' => Project::class,
            'selectable_id' => $project->id,
        ]);
    }

    #[Test]
    public function add_selection_prevents_duplicates(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $selection = $draft->selections()->first();

        $response = $this->post(
            route('resume-drafts.add-selection', [$draft, $requirements[0]]),
            [
                'selectable_type' => 'position',
                'selectable_id' => $selection->selectable_id,
            ],
        );

        $response->assertRedirect();
        // Should still have exactly 1 selection, not 2.
        $this->assertEquals(1, $draft->selections()->count());
    }

    #[Test]
    public function add_selection_rejects_organization_type(): void
    {
        [$listing, $requirements, $org] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        $response = $this->post(
            route('resume-drafts.add-selection', [$draft, $requirements[0]]),
            ['selectable_type' => 'organization', 'selectable_id' => $org->id],
        );

        // Validation should reject 'organization' since it's not in SELECTABLE_TYPES.
        $response->assertSessionHasErrors('selectable_type');
    }

    #[Test]
    public function remove_selection_deletes_user_added(): void
    {
        [$listing, $requirements, $org] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $project = Project::create([
            'organization_id' => $org->id,
            'name' => 'Test Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        // Create a user-added selection (no ai_reasoning).
        $userSelection = ResumeSelection::create([
            'resume_draft_id' => $draft->id,
            'job_listing_requirement_id' => $requirements[0]->id,
            'selectable_type' => Project::class,
            'selectable_id' => $project->id,
            'selected' => true,
            'display_order' => 1,
        ]);

        $response = $this->deleteJson(
            route('resume-drafts.remove-selection', [$draft, $userSelection]),
        );

        $response->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('resume_selections', ['id' => $userSelection->id]);
    }

    #[Test]
    public function remove_selection_blocks_ai_suggested(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $aiSelection = $draft->selections()->first();

        $response = $this->deleteJson(
            route('resume-drafts.remove-selection', [$draft, $aiSelection]),
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('resume_selections', ['id' => $aiSelection->id]);
    }

    #[Test]
    public function submit_experience_creates_source_document(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        $response = $this->post(
            route('resume-drafts.submit-experience', [$draft, $requirements[0]]),
            ['experience_text' => 'I built a fraud detection system...'],
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('source_documents', [
            'origin' => 'requirement_response',
            'job_listing_requirement_id' => $requirements[0]->id,
            'body' => 'I built a fraud detection system...',
        ]);
    }

    // -----------------------------------------------------------------
    // Screen 3: Confirm
    // -----------------------------------------------------------------

    #[Test]
    public function confirm_page_renders(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $draft->update(['requirement_decisions' => [$requirements[0]->id => 'accepted']]);

        $response = $this->get(route('resume-drafts.confirm-page', $draft));

        $response->assertOk();
        $response->assertViewIs('resume-drafts.confirm');
    }

    #[Test]
    public function confirm_advances_status(): void
    {
        $this->mockGenerateDraft();

        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $draft->update(['requirement_decisions' => [$requirements[0]->id => 'accepted']]);

        $response = $this->post(route('resume-drafts.confirm', $draft));

        $response->assertRedirect(route('resume-drafts.edit', $draft));
        $fresh = $draft->fresh();
        $this->assertEquals('editing', $fresh->status);
        $this->assertNotNull($fresh->generated_content);
        $this->assertNotNull($fresh->user_content);
    }

    #[Test]
    public function confirm_requires_accepted_requirement(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $draft->update(['requirement_decisions' => [$requirements[0]->id => 'rejected']]);

        $response = $this->post(route('resume-drafts.confirm', $draft));

        $response->assertRedirect(route('resume-drafts.show', $draft));
        $this->assertEquals('selecting', $draft->fresh()->status);
    }

    #[Test]
    public function confirm_requires_at_least_one_included_selection(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $draft->update(['requirement_decisions' => [$requirements[0]->id => 'accepted']]);
        // Exclude all selections.
        $draft->selections()->update(['selected' => false]);

        $response = $this->post(route('resume-drafts.confirm', $draft));

        $response->assertRedirect(route('resume-drafts.confirm-page', $draft));
        $this->assertEquals('selecting', $draft->fresh()->status);
    }

    #[Test]
    public function confirm_counts_duplicate_selections(): void
    {
        $this->mockGenerateDraft();

        [$listing, $requirements] = $this->makeListingWithRequirements(2);
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        // Accept first, mark second as duplicate. Exclude all selections
        // under the accepted requirement but keep them under the duplicate.
        $draft->update(['requirement_decisions' => [
            $requirements[0]->id => 'accepted',
            $requirements[1]->id => ['duplicate_of' => $requirements[0]->id],
        ]]);
        $draft->selections()
            ->where('job_listing_requirement_id', $requirements[0]->id)
            ->update(['selected' => false]);

        // The duplicate's selections are still included, so confirm should succeed.
        $response = $this->post(route('resume-drafts.confirm', $draft));

        $response->assertRedirect();
        $this->assertEquals('editing', $draft->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Catalog search
    // -----------------------------------------------------------------

    #[Test]
    public function catalog_search_returns_results(): void
    {
        $org = Organization::create(['name' => 'Acme Corp', 'type' => 'employer']);
        Position::create([
            'organization_id' => $org->id,
            'title' => 'Developer at Acme',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ]);

        $response = $this->getJson(route('resume-drafts.catalog-search', ['q' => 'Acme']));

        $response->assertOk();
        $data = $response->json();
        $this->assertNotEmpty($data);
        $this->assertEquals('position', $data[0]['type']);
    }

    #[Test]
    public function catalog_search_finds_by_org_name(): void
    {
        $org = Organization::create(['name' => 'Chive Charities', 'type' => 'employer']);
        Project::create([
            'organization_id' => $org->id,
            'name' => 'Grant Application Platform',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $response = $this->getJson(route('resume-drafts.catalog-search', ['q' => 'Chive']));

        $response->assertOk();
        $data = $response->json();
        $this->assertNotEmpty($data);
        $this->assertEquals('project', $data[0]['type']);
        $this->assertEquals('Grant Application Platform', $data[0]['name']);
    }

    #[Test]
    public function catalog_search_excludes_organizations(): void
    {
        Organization::create(['name' => 'Searchable Org', 'type' => 'employer']);

        $response = $this->getJson(route('resume-drafts.catalog-search', ['q' => 'Searchable']));

        $response->assertOk();
        $data = $response->json();
        // Should not return org type results.
        $orgResults = array_filter($data, fn ($r) => $r['type'] === 'organization');
        $this->assertEmpty($orgResults);
    }

    #[Test]
    public function catalog_search_returns_empty_for_short_query(): void
    {
        $response = $this->getJson(route('resume-drafts.catalog-search', ['q' => '']));

        $response->assertOk();
        $this->assertEmpty($response->json());
    }

    // -----------------------------------------------------------------
    // Status routing (show)
    // -----------------------------------------------------------------

    #[Test]
    public function show_redirects_editing_to_edit(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftInEditing($listing, $requirements);

        $response = $this->get(route('resume-drafts.show', $draft));

        $response->assertRedirect(route('resume-drafts.edit', $draft));
    }

    #[Test]
    public function show_redirects_approved_to_edit(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftInEditing($listing, $requirements);
        $draft->update(['status' => 'approved']);

        $response = $this->get(route('resume-drafts.show', $draft));

        $response->assertRedirect(route('resume-drafts.edit', $draft));
    }

    #[Test]
    public function show_recovers_stale_drafting_status(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);
        $draft->update([
            'requirement_decisions' => [$requirements[0]->id => 'accepted'],
            'status' => 'drafting',
        ]);

        $response = $this->get(route('resume-drafts.show', $draft));

        // Should reset to selecting and render triage.
        $response->assertOk();
        $this->assertEquals('selecting', $draft->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Editing phase
    // -----------------------------------------------------------------

    #[Test]
    public function edit_renders_for_editing_status(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftInEditing($listing, $requirements);

        $response = $this->get(route('resume-drafts.edit', $draft));

        $response->assertOk();
        $response->assertSee('Edit resume draft');
    }

    #[Test]
    public function edit_renders_for_approved_status(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftInEditing($listing, $requirements);
        $draft->update(['status' => 'approved']);

        $response = $this->get(route('resume-drafts.edit', $draft));

        $response->assertOk();
        $response->assertSee('Approved draft');
    }

    #[Test]
    public function edit_redirects_selecting_to_show(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        $response = $this->get(route('resume-drafts.edit', $draft));

        $response->assertRedirect(route('resume-drafts.show', $draft));
    }

    #[Test]
    public function update_content_saves(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftInEditing($listing, $requirements);

        $response = $this->post(route('resume-drafts.update-content', $draft), [
            'user_content' => '# Edited Resume',
        ]);

        $response->assertRedirect(route('resume-drafts.edit', $draft));
        $this->assertEquals('# Edited Resume', $draft->fresh()->user_content);
    }

    #[Test]
    public function update_content_rejects_non_editing(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftInEditing($listing, $requirements);
        $draft->update(['status' => 'approved']);

        $response = $this->post(route('resume-drafts.update-content', $draft), [
            'user_content' => '# Should Not Save',
        ]);

        $response->assertRedirect();
        $this->assertNotEquals('# Should Not Save', $draft->fresh()->user_content);
    }

    #[Test]
    public function revert_restores_generated_content(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftInEditing($listing, $requirements);
        $draft->update(['user_content' => '# User Edits']);

        $response = $this->post(route('resume-drafts.revert', $draft));

        $response->assertRedirect(route('resume-drafts.edit', $draft));
        $this->assertEquals('# AI Generated Resume', $draft->fresh()->user_content);
    }

    #[Test]
    public function approve_advances_to_approved(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftInEditing($listing, $requirements);

        $response = $this->post(route('resume-drafts.approve', $draft));

        $response->assertRedirect(route('resume-drafts.edit', $draft));
        $this->assertEquals('approved', $draft->fresh()->status);
    }

    #[Test]
    public function approve_rejects_non_editing(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftWithSelections($listing, $requirements);

        $response = $this->post(route('resume-drafts.approve', $draft));

        $response->assertRedirect();
        $this->assertEquals('selecting', $draft->fresh()->status);
    }

    #[Test]
    public function revise_selections_resets_to_selecting(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftInEditing($listing, $requirements);

        $response = $this->post(route('resume-drafts.revise-selections', $draft));

        $response->assertRedirect(route('resume-drafts.show', $draft));
        $fresh = $draft->fresh();
        $this->assertEquals('selecting', $fresh->status);
        $this->assertNull($fresh->generated_content);
        $this->assertNull($fresh->user_content);
    }

    #[Test]
    public function revise_selections_works_from_approved(): void
    {
        [$listing, $requirements] = $this->makeListingWithRequirements(1);
        $draft = $this->makeDraftInEditing($listing, $requirements);
        $draft->update(['status' => 'approved']);

        $response = $this->post(route('resume-drafts.revise-selections', $draft));

        $response->assertRedirect(route('resume-drafts.show', $draft));
        $this->assertEquals('selecting', $draft->fresh()->status);
    }
}