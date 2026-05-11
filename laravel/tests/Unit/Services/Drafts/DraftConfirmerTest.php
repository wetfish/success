<?php

namespace Tests\Unit\Services\Drafts;

use App\Models\Accomplishment;
use App\Models\ExtractedRecord;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Services\Drafts\DraftConfirmationException;
use App\Services\Drafts\DraftConfirmer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DraftConfirmerTest extends TestCase
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

    /**
     * Create a Position with the schema's required fields filled in.
     * Tests overriding any field can do so via $overrides; everything
     * else gets a sensible default.
     */
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

    /**
     * Create a Project with the schema's required fields filled in.
     */
    private function makeProject(Organization $org, array $overrides = []): Project
    {
        return Project::create(array_merge([
            'organization_id' => $org->id,
            'name' => 'A project',
            'visibility' => 'internal',
            'contribution_level' => 'core',
        ], $overrides));
    }

    /**
     * The minimum payload fields needed for the DraftConfirmer to
     * successfully create a Position. The AI extracts these in
     * practice; tests use these defaults unless explicitly overriding.
     */
    private function positionPayload(array $overrides = []): array
    {
        return array_merge([
            'organization_name' => 'Acme',
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2020-01-01',
            'location_arrangement' => 'remote',
        ], $overrides);
    }

    /**
     * The minimum payload fields needed for the DraftConfirmer to
     * successfully create a Project.
     */
    private function projectPayload(array $overrides = []): array
    {
        return array_merge([
            'organization_name' => 'Acme',
            'name' => 'A project',
            'visibility' => 'internal',
            'contribution_level' => 'core',
        ], $overrides);
    }

    /* Organizations =================================================== */

    #[Test]
    public function it_creates_an_organization_from_a_draft(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Lightning Labs',
            'type' => 'employer',
            'website' => 'https://example.com',
        ]);

        $org = (new DraftConfirmer())->confirm($draft);

        $this->assertInstanceOf(Organization::class, $org);
        $this->assertSame('Lightning Labs', $org->name);
        $this->assertSame('employer', $org->type);
    }

    #[Test]
    public function confirming_an_organization_marks_the_draft_confirmed_with_match_record(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme', 'type' => 'employer']);

        $org = (new DraftConfirmer())->confirm($draft);

        $draft->refresh();
        $this->assertSame('confirmed', $draft->status);
        $this->assertSame('organization', $draft->match_record_type);
        $this->assertSame($org->id, $draft->match_record_id);
    }

    #[Test]
    public function unknown_payload_fields_are_ignored(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
            'confidence_score' => 0.95,  // not in fillable
            'totally_made_up_field' => 'whatever',
        ]);

        $org = (new DraftConfirmer())->confirm($draft);

        $this->assertSame('Acme', $org->name);
        // No error — extra fields silently dropped.
    }

    /* Positions ======================================================= */

    #[Test]
    public function it_creates_a_position_when_org_exists(): void
    {
        $org = Organization::create(['name' => 'Lightning Labs', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', $this->positionPayload([
            'organization_name' => 'Lightning Labs',
            'title' => 'Software Engineer',
        ]));

        $position = (new DraftConfirmer())->confirm($draft);

        $this->assertInstanceOf(Position::class, $position);
        $this->assertSame('Software Engineer', $position->title);
        $this->assertSame($org->id, $position->organization_id);
    }

    #[Test]
    public function position_org_lookup_is_case_insensitive(): void
    {
        Organization::create(['name' => 'Lightning Labs', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', $this->positionPayload([
            'organization_name' => 'lightning labs',  // different case
        ]));

        $position = (new DraftConfirmer())->confirm($draft);

        $this->assertNotNull($position->organization_id);
    }

    #[Test]
    public function confirming_a_position_fails_when_org_not_found(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Nonexistent Corp',
            'title' => 'Engineer',
        ]);

        $this->expectException(DraftConfirmationException::class);
        $this->expectExceptionMessage('Nonexistent Corp');

        (new DraftConfirmer())->confirm($draft);
    }

    #[Test]
    public function failed_position_confirmation_leaves_draft_pending(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', [
            'organization_name' => 'Nonexistent',
            'title' => 'Engineer',
        ]);

        try {
            (new DraftConfirmer())->confirm($draft);
        } catch (DraftConfirmationException) {
            // expected
        }

        $draft->refresh();
        $this->assertSame('pending', $draft->status);
        $this->assertNull($draft->match_record_id);
        $this->assertSame(0, Position::count());
    }

    /* Projects ======================================================== */

    #[Test]
    public function it_creates_a_project_with_org_and_position_refs(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $position = $this->makePosition($org);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', $this->projectPayload([
            'organization_name' => 'Acme',
            'position_title' => 'Engineer',
            'name' => 'Dashboard rewrite',
            'description' => 'Rebuilt the analytics dashboard',
        ]));

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertSame('Dashboard rewrite', $project->name);
        $this->assertSame($org->id, $project->organization_id);
        $this->assertSame($position->id, $project->position_id);
    }

    #[Test]
    public function project_position_ref_is_optional(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', $this->projectPayload([
            'organization_name' => 'Acme',
            'name' => 'Open source contribution',
        ]));

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertSame($org->id, $project->organization_id);
        $this->assertNull($project->position_id);
    }

    #[Test]
    public function project_can_have_a_parent_project(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $parent = $this->makeProject($org, ['name' => 'Platform rewrite']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', $this->projectPayload([
            'organization_name' => 'Acme',
            'parent_project_name' => 'Platform rewrite',
            'name' => 'Auth module',
        ]));

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertSame($parent->id, $project->parent_project_id);
    }

    #[Test]
    public function project_fails_when_parent_project_not_found(): void
    {
        Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'parent_project_name' => 'Nonexistent parent',
            'name' => 'Sub-project',
        ]);

        $this->expectException(DraftConfirmationException::class);

        (new DraftConfirmer())->confirm($draft);
    }

    /* Accomplishments ================================================= */

    #[Test]
    public function it_creates_an_accomplishment_attached_to_a_project(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $project = $this->makeProject($org, ['name' => 'Dashboard rewrite']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            'organization_name' => 'Acme',
            'project_name' => 'Dashboard rewrite',
            'title' => 'Reduced load time by 40%',
            'description' => 'Profiled and optimized the data pipeline',
            'date' => '2024-06-15',
        ]);

        $accomplishment = (new DraftConfirmer())->confirm($draft);

        $this->assertInstanceOf(Accomplishment::class, $accomplishment);
        $this->assertSame($project->id, $accomplishment->project_id);
        $this->assertNull($accomplishment->position_id);
    }

    #[Test]
    public function it_creates_an_accomplishment_attached_to_a_position(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $position = $this->makePosition($org);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            'organization_name' => 'Acme',
            'position_title' => 'Engineer',
            'title' => 'Mentored junior engineers',
            'description' => 'Paired with two new hires through their first quarter',
            'date' => '2024-06-15',
        ]);

        $accomplishment = (new DraftConfirmer())->confirm($draft);

        $this->assertNull($accomplishment->project_id);
        $this->assertSame($position->id, $accomplishment->position_id);
    }

    #[Test]
    public function accomplishment_fails_with_neither_project_nor_position(): void
    {
        Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            'organization_name' => 'Acme',
            'title' => 'A floating accomplishment',
        ]);

        $this->expectException(DraftConfirmationException::class);

        (new DraftConfirmer())->confirm($draft);
    }

    #[Test]
    public function accomplishment_without_org_name_resolves_project_globally(): void
    {
        // The AI sometimes omits organization_name on accomplishments
        // when project_name alone uniquely identifies the parent.
        // Confirm that we handle this gracefully via global project
        // lookup.
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $project = $this->makeProject($org, ['name' => 'PCI Compliance']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            // No organization_name here — only project_name.
            'project_name' => 'PCI Compliance',
            'title' => 'Scoped compliance to Skyflow boundary',
            'description' => 'Avoided full infrastructure certification',
            'date' => '2024-06-15',
        ]);

        $accomplishment = (new DraftConfirmer())->confirm($draft);

        $this->assertSame($project->id, $accomplishment->project_id);
    }

    #[Test]
    public function accomplishment_without_org_fails_when_project_name_is_ambiguous(): void
    {
        // Two orgs each have a project of the same name. Without
        // organization_name to disambiguate, the lookup should error
        // with a helpful message asking the user to specify the org.
        $orgA = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $orgB = Organization::create(['name' => 'Beta Inc', 'type' => 'employer']);
        $this->makeProject($orgA, ['name' => 'Q4 planning']);
        $this->makeProject($orgB, ['name' => 'Q4 planning']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            'project_name' => 'Q4 planning',
            'title' => 'Led the planning session',
            'description' => 'Facilitated the roadmap discussion',
            'date' => '2024-10-01',
        ]);

        $this->expectException(DraftConfirmationException::class);
        $this->expectExceptionMessage('multiple projects');

        (new DraftConfirmer())->confirm($draft);
    }

    #[Test]
    public function position_attached_accomplishment_still_requires_org_name(): void
    {
        // Positions are identified by org+title, so without an org
        // we can't unambiguously resolve a bare position_title like
        // "Engineer". Confirm we still surface a helpful message.
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            'position_title' => 'Engineer',
            'title' => 'Mentored junior engineers',
            'description' => 'Paired with two new hires',
            'date' => '2024-06-15',
        ]);

        $this->expectException(DraftConfirmationException::class);
        $this->expectExceptionMessage('organization_name');

        (new DraftConfirmer())->confirm($draft);
    }

    /* General ========================================================= */

    #[Test]
    public function confirming_an_already_confirmed_draft_fails(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', ['name' => 'Acme']);
        $draft->update(['status' => 'confirmed']);

        $this->expectException(DraftConfirmationException::class);

        (new DraftConfirmer())->confirm($draft);
    }
}