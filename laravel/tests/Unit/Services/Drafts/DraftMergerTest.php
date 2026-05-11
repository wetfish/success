<?php

namespace Tests\Unit\Services\Drafts;

use App\Models\Accomplishment;
use App\Models\ExtractedRecord;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Services\Drafts\DraftMerger;
use App\Services\Drafts\DraftMergerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DraftMergerTest extends TestCase
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

    private function makePosition(Organization $org, array $overrides = []): Position
    {
        return Position::create(array_merge([
            'organization_id' => $org->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ], $overrides));
    }

    private function makeProject(Organization $org, array $overrides = []): Project
    {
        return Project::create(array_merge([
            'organization_id' => $org->id,
            'name' => 'A project',
            'visibility' => 'internal',
            'contribution_level' => 'core',
        ], $overrides));
    }

    /* Basic merge mechanics =========================================== */

    #[Test]
    public function merges_an_organization_with_chosen_field_values(): void
    {
        $target = Organization::create([
            'name' => 'Lightning Labs Inc',
            'type' => 'employer',
            'description' => 'Old description',
        ]);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Lightning',
            'description' => 'New description',
        ]);

        $merged = (new DraftMerger())->merge($draft, $target, [
            'name' => 'Lightning Labs Inc',
            'description' => 'New description',
        ]);

        $this->assertSame('Lightning Labs Inc', $merged->name);
        $this->assertSame('New description', $merged->description);
    }

    #[Test]
    public function merging_marks_the_draft_merged_with_match_record_pointer(): void
    {
        $target = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
        ]);

        (new DraftMerger())->merge($draft, $target, []);

        $draft->refresh();
        $this->assertSame('merged', $draft->status);
        $this->assertSame(Organization::class, $draft->match_record_type);
        $this->assertSame($target->id, $draft->match_record_id);
    }

    #[Test]
    public function non_fillable_payload_keys_are_filtered_out(): void
    {
        $target = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme', 'type' => 'employer']);

        // The chooser submits an extra key that isn't on the model.
        // Merger should silently drop it and let the merge succeed.
        $merged = (new DraftMerger())->merge($draft, $target, [
            'name' => 'Acme Renamed',
            'totally_made_up_field' => 'should be dropped',
        ]);

        $this->assertSame('Acme Renamed', $merged->name);
    }

    #[Test]
    public function empty_field_choices_keeps_target_values_unchanged(): void
    {
        $target = Organization::create([
            'name' => 'Acme',
            'type' => 'employer',
            'description' => 'Original description',
        ]);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'description' => 'Different description',
        ]);

        (new DraftMerger())->merge($draft, $target, []);

        $target->refresh();
        $this->assertSame('Original description', $target->description);
    }

    /* Dependent rewriting ============================================= */

    #[Test]
    public function organization_merge_rewrites_dependent_position_drafts(): void
    {
        // Org draft "Acme" merges into target "ACME Inc". The position
        // draft's organization_name should be rewritten to "ACME Inc"
        // so later confirmation resolves against the target.
        $target = Organization::create(['name' => 'ACME Inc', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $orgDraft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
        ]);
        $positionDraft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Acme',
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ]);

        (new DraftMerger())->merge($orgDraft, $target, []);

        $positionDraft->refresh();
        $this->assertSame('ACME Inc', $positionDraft->payload['organization_name']);
        // Other payload fields are unchanged.
        $this->assertSame('Engineer', $positionDraft->payload['title']);
    }

    #[Test]
    public function position_merge_rewrites_position_title_in_dependent_drafts(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $target = $this->makePosition($org, ['title' => 'Sr. Engineer']);
        $doc = $this->makeDocument();
        $positionDraft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Acme',
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ]);
        $projectDraft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'position_title' => 'Senior Engineer',
            'name' => 'A project',
            'visibility' => 'internal',
            'contribution_level' => 'core',
        ]);

        (new DraftMerger())->merge($positionDraft, $target, []);

        $projectDraft->refresh();
        $this->assertSame('Sr. Engineer', $projectDraft->payload['position_title']);
        // organization_name unchanged — only the title was merged.
        $this->assertSame('Acme', $projectDraft->payload['organization_name']);
    }

    #[Test]
    public function project_merge_rewrites_project_name_in_dependent_accomplishment_drafts(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $target = $this->makeProject($org, ['name' => 'Migration v2']);
        $doc = $this->makeDocument();
        $projectDraft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'Migration',
            'visibility' => 'internal',
            'contribution_level' => 'core',
        ]);
        $accomplishmentDraft = $this->makeDraft($doc, 'accomplishment', [
            'organization_name' => 'Acme',
            'project_name' => 'Migration',
            'title' => 'Shipped on time',
            'description' => 'Description',
            'date' => '2024-01-01',
        ]);

        (new DraftMerger())->merge($projectDraft, $target, []);

        $accomplishmentDraft->refresh();
        $this->assertSame('Migration v2', $accomplishmentDraft->payload['project_name']);
    }

    #[Test]
    public function rewrite_uses_chosen_target_name_not_original(): void
    {
        // If the user chose "Use draft" for the org's name, the
        // target's name updates to the draft value. Dependent
        // organization_name references should pick up that new value,
        // not the target's pre-merge name. This verifies the rewrite
        // step reads the post-update target.
        $target = Organization::create(['name' => 'Old Name Inc', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $orgDraft = $this->makeDraft($doc, 'organization', [
            'name' => 'Old Name',
            'type' => 'employer',
        ]);
        $positionDraft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Old Name',
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ]);

        (new DraftMerger())->merge($orgDraft, $target, [
            'name' => 'Brand New Name',  // user picks a third name
        ]);

        $positionDraft->refresh();
        $this->assertSame('Brand New Name', $positionDraft->payload['organization_name']);
    }

    #[Test]
    public function dependents_from_other_source_documents_are_not_rewritten(): void
    {
        // findDependents() scopes to same source_document_id; a draft
        // in a different document referencing the same name should
        // be left alone.
        $target = Organization::create(['name' => 'ACME Inc', 'type' => 'employer']);
        $doc1 = $this->makeDocument();
        $doc2 = $this->makeDocument();
        $orgDraft = $this->makeDraft($doc1, 'organization', ['name' => 'Acme', 'type' => 'employer']);
        $otherDocPositionDraft = $this->makeDraft($doc2, 'position', [
            'organization_name' => 'Acme',
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ]);

        (new DraftMerger())->merge($orgDraft, $target, []);

        $otherDocPositionDraft->refresh();
        $this->assertSame('Acme', $otherDocPositionDraft->payload['organization_name']);
    }

    #[Test]
    public function non_pending_dependents_are_not_rewritten(): void
    {
        // Already-confirmed dependents have backing catalog records;
        // changing the payload would diverge them from reality.
        // findDependents() filters status='pending', so this shouldn't
        // happen — verifying explicitly.
        $target = Organization::create(['name' => 'ACME Inc', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $orgDraft = $this->makeDraft($doc, 'organization', ['name' => 'Acme', 'type' => 'employer']);
        $confirmedPositionDraft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Acme',
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ]);
        $confirmedPositionDraft->update(['status' => 'confirmed']);

        (new DraftMerger())->merge($orgDraft, $target, []);

        $confirmedPositionDraft->refresh();
        $this->assertSame('Acme', $confirmedPositionDraft->payload['organization_name']);
    }

    /* Error paths ===================================================== */

    #[Test]
    public function merging_a_non_pending_draft_throws(): void
    {
        $target = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);
        $draft->update(['status' => 'rejected']);

        $this->expectException(DraftMergerException::class);

        (new DraftMerger())->merge($draft, $target, []);
    }

    #[Test]
    public function merging_into_wrong_target_type_throws(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $position = $this->makePosition($org);
        $doc = $this->makeDocument();
        $orgDraft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);

        $this->expectException(DraftMergerException::class);

        (new DraftMerger())->merge($orgDraft, $position, []);
    }

    #[Test]
    public function merging_into_soft_deleted_target_throws(): void
    {
        $target = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $target->delete();
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);

        $this->expectException(DraftMergerException::class);

        (new DraftMerger())->merge($draft, $target, []);
    }

    #[Test]
    public function accomplishment_drafts_cannot_be_merged(): void
    {
        // The detector doesn't surface accomplishment candidates and
        // the merger doesn't support that record type. If a controller
        // somehow tried, we fail loudly rather than producing surprising
        // results.
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $project = $this->makeProject($org);
        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'title' => 'Old',
            'description' => 'Old',
            'date' => '2024-01-01',
        ]);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            'organization_name' => 'Acme',
            'project_name' => $project->name,
            'title' => 'New',
            'description' => 'New',
            'date' => '2024-01-01',
        ]);

        $this->expectException(DraftMergerException::class);

        (new DraftMerger())->merge($draft, $accomplishment, []);
    }

    #[Test]
    public function model_invariant_failure_leaves_state_unchanged(): void
    {
        // Project::validateInvariants forbids a sub-project from
        // pointing at a parent in a different organization. Set up
        // a merge that would violate this and verify the transaction
        // rolls back: target unchanged, draft still pending.
        $orgA = Organization::create(['name' => 'A', 'type' => 'employer']);
        $orgB = Organization::create(['name' => 'B', 'type' => 'employer']);
        $parentAtOrgA = $this->makeProject($orgA, ['name' => 'Parent']);
        $target = $this->makeProject($orgB, ['name' => 'Target']);

        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'B',
            'name' => 'Target',
            'visibility' => 'internal',
            'contribution_level' => 'core',
        ]);

        try {
            (new DraftMerger())->merge($draft, $target, [
                'parent_project_id' => $parentAtOrgA->id,
            ]);
            $this->fail('Expected DraftMergerException');
        } catch (DraftMergerException) {
            // expected
        }

        $target->refresh();
        $this->assertNull($target->parent_project_id);

        $draft->refresh();
        $this->assertSame('pending', $draft->status);
        $this->assertNull($draft->match_record_id);
    }
}