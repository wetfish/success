<?php

namespace Tests\Unit\Services\Drafts;

use App\Models\Accomplishment;
use App\Models\ExtractedRecord;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Services\Drafts\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DuplicateDetectorTest extends TestCase
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

    /* Organizations ==================================================== */

    #[Test]
    public function detects_organization_with_exact_case_insensitive_name_match(): void
    {
        $org = Organization::create(['name' => 'Lightning Labs', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'lightning labs']);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertCount(1, $candidates);
        $this->assertSame($org->id, $candidates->first()->id);
    }

    #[Test]
    public function detects_organization_when_draft_name_is_substring_of_existing(): void
    {
        $org = Organization::create(['name' => 'Lightning Labs Inc', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Lightning']);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertCount(1, $candidates);
        $this->assertSame($org->id, $candidates->first()->id);
    }

    #[Test]
    public function detects_organization_when_existing_name_is_substring_of_draft(): void
    {
        // The reverse direction — sometimes the AI extracts a more
        // verbose form than what's in the catalog. Both directions
        // count as candidates.
        $org = Organization::create(['name' => 'Stripe', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Stripe Inc Holdings']);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertCount(1, $candidates);
        $this->assertSame($org->id, $candidates->first()->id);
    }

    #[Test]
    public function returns_empty_when_no_organization_matches(): void
    {
        Organization::create(['name' => 'Anthropic', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'OpenAI']);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertTrue($candidates->isEmpty());
    }

    #[Test]
    public function returns_empty_when_draft_organization_name_is_missing(): void
    {
        Organization::create(['name' => 'Lightning Labs', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', []);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertTrue($candidates->isEmpty());
    }

    #[Test]
    public function organization_match_returns_multiple_candidates_when_many_qualify(): void
    {
        Organization::create(['name' => 'Lightning Labs', 'type' => 'employer']);
        Organization::create(['name' => 'Lightning Rods Inc', 'type' => 'client']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Lightning']);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertCount(2, $candidates);
    }

    #[Test]
    public function soft_deleted_organizations_are_not_candidates(): void
    {
        $org = Organization::create(['name' => 'Lightning Labs', 'type' => 'employer']);
        $org->delete();
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Lightning Labs']);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertTrue($candidates->isEmpty());
    }

    /* Positions ======================================================== */

    #[Test]
    public function detects_position_with_exact_title_within_named_org(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $position = $this->makePosition($org, ['title' => 'Engineer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Acme',
            'title' => 'Engineer',
        ]);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertCount(1, $candidates);
        $this->assertSame($position->id, $candidates->first()->id);
    }

    #[Test]
    public function position_title_match_is_case_insensitive(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $this->makePosition($org, ['title' => 'Senior Engineer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'acme',
            'title' => 'senior engineer',
        ]);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertCount(1, $candidates);
    }

    #[Test]
    public function position_with_substring_only_title_is_not_a_candidate(): void
    {
        // Positions require exact title match — unlike orgs/projects,
        // partial matches aren't surfaced. Different titles at the
        // same org are different roles.
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $this->makePosition($org, ['title' => 'Senior Software Engineer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Acme',
            'title' => 'Engineer',
        ]);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertTrue($candidates->isEmpty());
    }

    #[Test]
    public function position_match_is_scoped_to_the_named_org(): void
    {
        $orgA = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $orgB = Organization::create(['name' => 'Beta Inc', 'type' => 'employer']);
        $this->makePosition($orgA, ['title' => 'Engineer']);
        $positionB = $this->makePosition($orgB, ['title' => 'Engineer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Beta Inc',
            'title' => 'Engineer',
        ]);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertCount(1, $candidates);
        $this->assertSame($positionB->id, $candidates->first()->id);
    }

    #[Test]
    public function position_match_returns_empty_when_parent_org_is_not_in_catalog(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Nonexistent Corp',
            'title' => 'Engineer',
        ]);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertTrue($candidates->isEmpty());
    }

    /* Projects ========================================================= */

    #[Test]
    public function detects_project_with_substring_name_within_named_org(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $project = $this->makeProject($org, ['name' => 'Payments Rewrite']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'Payments',
        ]);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertCount(1, $candidates);
        $this->assertSame($project->id, $candidates->first()->id);
    }

    #[Test]
    public function project_match_excludes_same_name_at_a_different_org(): void
    {
        // Cross-org project matches are deliberately not surfaced —
        // "Migration" at company A is a different body of work than
        // "Migration" at company B.
        $orgA = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $orgB = Organization::create(['name' => 'Beta Inc', 'type' => 'employer']);
        $this->makeProject($orgA, ['name' => 'Migration']);
        $projectB = $this->makeProject($orgB, ['name' => 'Migration']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Beta Inc',
            'name' => 'Migration',
        ]);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertCount(1, $candidates);
        $this->assertSame($projectB->id, $candidates->first()->id);
    }

    #[Test]
    public function project_match_returns_empty_when_parent_org_is_not_in_catalog(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Nonexistent Corp',
            'name' => 'Migration',
        ]);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertTrue($candidates->isEmpty());
    }

    /* Accomplishments ================================================== */

    #[Test]
    public function accomplishments_never_have_candidates(): void
    {
        // Accomplishments aren't scoped by slice 4.5 — too much title
        // variance for naïve string matching. Detection always returns
        // empty regardless of catalog contents.
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $project = $this->makeProject($org, ['name' => 'Migration']);
        Accomplishment::create([
            'project_id' => $project->id,
            'title' => 'Shipped on time',
            'description' => 'Description',
            'date' => '2024-01-01',
        ]);

        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            'organization_name' => 'Acme',
            'project_name' => 'Migration',
            'title' => 'Shipped on time',
            'description' => 'Description',
            'date' => '2024-01-02',
        ]);

        $candidates = (new DuplicateDetector())->findCandidates($draft);

        $this->assertTrue($candidates->isEmpty());
    }
}