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

    // ────────────────────────────────────────────────────────────
    // Helpers for the new draft types
    // ────────────────────────────────────────────────────────────

    private function makePerson(string $name = 'Sarah Chen', array $overrides = []): \App\Models\Person
    {
        return \App\Models\Person::create(array_merge([
            'name' => $name,
        ], $overrides));
    }

    private function makeTag(string $name, ?string $category = null): \App\Models\Tag
    {
        return \App\Models\Tag::create(['name' => $name, 'category' => $category]);
    }

    private function makeAccomplishment(Project $project, array $overrides = []): Accomplishment
    {
        return Accomplishment::create(array_merge([
            'project_id' => $project->id,
            'title' => 'A win',
            'description' => 'Did a thing',
            'date' => '2023-01-01',
            'confidence' => 3,
            'prominence' => 3,
        ], $overrides));
    }

    // ────────────────────────────────────────────────────────────
    // Person draft confirmation
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function confirming_a_person_draft_with_just_a_name_creates_the_person(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'person', ['name' => 'Sarah Chen']);

        $person = (new DraftConfirmer())->confirm($draft);

        $this->assertInstanceOf(\App\Models\Person::class, $person);
        $this->assertSame('Sarah Chen', $person->name);
        $this->assertSame('confirmed', $draft->fresh()->status);
    }

    #[Test]
    public function confirming_a_person_draft_with_full_fields_creates_the_person_with_them(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'person', [
            'name' => 'Sarah Chen',
            'current_title' => 'VP Engineering',
            'email' => 'sarah@example.com',
            'relationship_type' => 'manager',
        ]);

        $person = (new DraftConfirmer())->confirm($draft);

        $this->assertSame('Sarah Chen', $person->name);
        $this->assertSame('VP Engineering', $person->current_title);
        $this->assertSame('sarah@example.com', $person->email);
        $this->assertSame('manager', $person->relationship_type);
    }

    #[Test]
    public function person_draft_resolves_current_organization_by_name(): void
    {
        $doc = $this->makeDocument();
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $draft = $this->makeDraft($doc, 'person', [
            'name' => 'Sarah Chen',
            'current_organization_name' => 'Acme',
        ]);

        $person = (new DraftConfirmer())->confirm($draft);

        $this->assertSame($org->id, $person->current_organization_id);
    }

    #[Test]
    public function person_draft_with_unresolvable_organization_name_throws(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'person', [
            'name' => 'Sarah Chen',
            'current_organization_name' => 'Nonexistent Co',
        ]);

        $this->expectException(DraftConfirmationException::class);
        $this->expectExceptionMessage("Nonexistent Co");

        (new DraftConfirmer())->confirm($draft);
    }

    #[Test]
    public function person_draft_without_a_name_throws(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'person', ['current_title' => 'CEO']);

        $this->expectException(DraftConfirmationException::class);

        (new DraftConfirmer())->confirm($draft);
    }

    #[Test]
    public function person_draft_reuses_existing_record_with_matching_name(): void
    {
        // The common case: a collaborator slot auto-created Sarah
        // earlier, and now we're confirming her standalone Person
        // draft. We shouldn't create a duplicate.
        $existing = $this->makePerson('Sarah Chen');
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'person', [
            'name' => 'Sarah Chen',
            'current_title' => 'New Title',
        ]);

        $person = (new DraftConfirmer())->confirm($draft);

        $this->assertSame($existing->id, $person->id);
        $this->assertSame(1, \App\Models\Person::count());
        // We don't overwrite — the existing person's empty
        // current_title is left empty rather than getting the AI's data.
        $this->assertNull($person->current_title);
    }

    #[Test]
    public function person_name_lookup_is_case_insensitive(): void
    {
        $existing = $this->makePerson('Sarah Chen');
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'person', ['name' => 'SARAH CHEN']);

        $person = (new DraftConfirmer())->confirm($draft);

        $this->assertSame($existing->id, $person->id);
    }

    // ────────────────────────────────────────────────────────────
    // Nested tags on entity drafts
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function confirming_an_organization_with_nested_tags_attaches_them(): void
    {
        // Catalog tags must pre-exist for nested attachment to work
        // (no auto-create — see attachNestedTags docblock).
        $this->makeTag('B Corp', 'concept');
        $this->makeTag('Remote First', 'methodology');

        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
            'tags' => [
                ['name' => 'B Corp', 'category' => 'concept'],
                ['name' => 'Remote First', 'category' => 'methodology'],
            ],
        ]);

        $org = (new DraftConfirmer())->confirm($draft);

        $this->assertCount(2, $org->tags);
        $tagNames = $org->tags->pluck('name')->sort()->values()->all();
        $this->assertSame(['B Corp', 'Remote First'], $tagNames);
    }

    #[Test]
    public function nested_tag_resolution_uses_existing_tag_by_name(): void
    {
        // Existing tag exists; the AI emits its name. Should resolve
        // to the existing one rather than create a duplicate.
        $existing = $this->makeTag('Python', 'language');
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'Tooling',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'tags' => [['name' => 'Python', 'category' => 'language']],
        ]);

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertSame(1, \App\Models\Tag::count());
        $this->assertSame($existing->id, $project->tags->first()->id);
    }

    #[Test]
    public function nested_tag_resolution_uses_existing_tag_by_alias(): void
    {
        // The AI emits "postgres"; we have a "PostgreSQL" tag with
        // "postgres" as an alias. Should resolve to PostgreSQL.
        $pgTag = $this->makeTag('PostgreSQL', 'tool');
        $pgTag->aliases()->create(['alias' => 'postgres']);
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'Database work',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'tags' => [['name' => 'postgres', 'category' => 'tool']],
        ]);

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertSame(1, \App\Models\Tag::count());
        $this->assertSame($pgTag->id, $project->tags->first()->id);
    }

    #[Test]
    public function nested_tag_resolution_skips_tags_not_in_catalog(): void
    {
        // Behavior change in chunk 4a: attachNestedTags no longer
        // auto-creates tags. A name that doesn't match a catalog tag
        // (by canonical name or alias) is skipped. The user's tag
        // review decisions (step 1 of the wizard) determine catalog
        // state before entity-draft confirmation reaches this point.
        // A tag missing from the catalog means either (a) the user
        // rejected it during review or (b) tag review hasn't run yet.
        // Either way: skip, don't materialize.
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'A project',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'tags' => [['name' => 'Kubernetes', 'category' => 'tool']],
        ]);

        $project = (new DraftConfirmer())->confirm($draft);

        // No catalog tag created, no attachment.
        $this->assertSame(0, \App\Models\Tag::count());
        $this->assertCount(0, $project->tags);
    }

    #[Test]
    public function nested_tag_name_lookup_is_case_insensitive(): void
    {
        // AI emits "python", existing tag is "Python". Should resolve
        // to existing rather than create a duplicate.
        $existing = $this->makeTag('Python', 'language');
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'A project',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'tags' => [['name' => 'python', 'category' => 'language']],
        ]);

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertSame(1, \App\Models\Tag::count());
        $this->assertSame($existing->id, $project->tags->first()->id);
    }

    #[Test]
    public function confirming_an_accomplishment_with_nested_tags_attaches_them(): void
    {
        // Accomplishments are taggable too — the third entity type
        // with nested tag attachment behavior. Tags must pre-exist.
        $this->makeTag('Performance', 'concept');
        $this->makeTag('Migration', 'concept');

        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $project = $this->makeProject($org);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            'project_name' => $project->name,
            'organization_name' => 'Acme',
            'title' => 'A win',
            'description' => 'Did a thing',
            'date' => '2023-01-01',
            'confidence' => 3,
            'prominence' => 3,
            'tags' => [
                ['name' => 'Performance', 'category' => 'concept'],
                ['name' => 'Migration', 'category' => 'concept'],
            ],
        ]);

        $accomplishment = (new DraftConfirmer())->confirm($draft);

        $this->assertCount(2, $accomplishment->tags);
    }

    #[Test]
    public function nested_tag_attachment_does_not_overwrite_existing_category(): void
    {
        // The AI's emitted category for an entity-nested tag is purely
        // informational — it was used at tag review time (step 1) to
        // help the user categorize, but by entity-draft confirm time
        // (step 3+) the catalog tag's category is whatever the user
        // accepted. attachNestedTags reads from the catalog (via
        // preview) so the AI's category here is ignored entirely.
        $existing = $this->makeTag('Python', 'language');
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'A project',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'tags' => [['name' => 'Python', 'category' => 'tool']],
        ]);

        (new DraftConfirmer())->confirm($draft);

        $this->assertSame('language', $existing->fresh()->category);
    }

    // ────────────────────────────────────────────────────────────
    // Nested collaborators on entity drafts
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function confirming_a_position_with_nested_collaborators_attaches_them_with_roles(): void
    {
        // People must pre-exist for attachment (no auto-create —
        // see attachNestedCollaborators docblock).
        $this->makePerson('Sarah Chen');
        $this->makePerson('Alex Rivera');

        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', $this->positionPayload([
            'collaborators' => [
                ['name' => 'Sarah Chen', 'role' => 'Manager'],
                ['name' => 'Alex Rivera', 'role' => 'Peer'],
            ],
        ]));

        $position = (new DraftConfirmer())->confirm($draft);

        $this->assertCount(2, $position->collaborators);
        $roles = $position->collaborators->pluck('pivot.role_on_position')->sort()->values()->all();
        $this->assertSame(['Manager', 'Peer'], $roles);
    }

    #[Test]
    public function collaborator_resolution_uses_existing_person_by_name(): void
    {
        $existing = $this->makePerson('Sarah Chen');
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', $this->positionPayload([
            'collaborators' => [
                ['name' => 'Sarah Chen', 'role' => 'Manager'],
            ],
        ]));

        $position = (new DraftConfirmer())->confirm($draft);

        $this->assertSame(1, \App\Models\Person::count());
        $this->assertSame($existing->id, $position->collaborators->first()->id);
    }

    #[Test]
    public function collaborator_resolution_skips_people_not_in_catalog(): void
    {
        // Symmetric with nested_tag_resolution_skips_tags_not_in_catalog.
        // A person name with no catalog match is skipped, not
        // auto-created. The user's person review decisions (step 2 of
        // the wizard) gate which names exist in the catalog.
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', $this->positionPayload([
            'collaborators' => [
                ['name' => 'Brand New Person', 'role' => 'Manager'],
            ],
        ]));

        $position = (new DraftConfirmer())->confirm($draft);

        $this->assertSame(0, \App\Models\Person::count());
        $this->assertCount(0, $position->collaborators);
    }

    #[Test]
    public function collaborator_resolution_is_case_insensitive(): void
    {
        $existing = $this->makePerson('Sarah Chen');
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', $this->positionPayload([
            'collaborators' => [
                ['name' => 'SARAH CHEN', 'role' => 'Manager'],
            ],
        ]));

        $position = (new DraftConfirmer())->confirm($draft);

        $this->assertSame(1, \App\Models\Person::count());
        $this->assertSame($existing->id, $position->collaborators->first()->id);
    }

    #[Test]
    public function empty_collaborator_role_normalizes_to_null(): void
    {
        $this->makePerson('Sarah Chen');
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', $this->projectPayload([
            'collaborators' => [
                ['name' => 'Sarah Chen', 'role' => ''],
            ],
        ]));

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertNull($project->collaborators->first()->pivot->role_on_project);
    }

    #[Test]
    public function duplicate_collaborator_names_in_same_payload_dedupe(): void
    {
        // Defensive against AI emission errors — if the AI lists the
        // same person twice in collaborators, we shouldn't fail on
        // the pivot unique constraint. The sync logic keys by person
        // id, so the second entry overwrites the first.
        $this->makePerson('Sarah Chen');
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', $this->positionPayload([
            'collaborators' => [
                ['name' => 'Sarah Chen', 'role' => 'Manager'],
                ['name' => 'Sarah Chen', 'role' => 'Peer'],
            ],
        ]));

        $position = (new DraftConfirmer())->confirm($draft);

        $this->assertCount(1, $position->collaborators);
        // Last write wins.
        $this->assertSame('Peer', $position->collaborators->first()->pivot->role_on_position);
    }

    #[Test]
    public function confirming_an_accomplishment_with_nested_collaborators_attaches_them(): void
    {
        $this->makePerson('Sarah Chen');
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $project = $this->makeProject($org);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            'project_name' => $project->name,
            'organization_name' => 'Acme',
            'title' => 'Shipped it',
            'description' => 'Description',
            'date' => '2023-01-01',
            'confidence' => 3,
            'prominence' => 3,
            'collaborators' => [
                ['name' => 'Sarah Chen', 'role' => 'Reviewer'],
            ],
        ]);

        $accomplishment = (new DraftConfirmer())->confirm($draft);

        $this->assertCount(1, $accomplishment->collaborators);
        $this->assertSame('Reviewer', $accomplishment->collaborators->first()->pivot->role_on_accomplishment);
    }

    // ────────────────────────────────────────────────────────────
    // Nested links on entity drafts
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function confirming_an_organization_with_nested_links_attaches_them(): void
    {
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
            'links' => [
                ['url' => 'https://acme.example.com', 'type' => 'website'],
                ['url' => 'https://acme.example.com/careers', 'type' => 'careers'],
            ],
        ]);

        $org = (new DraftConfirmer())->confirm($draft);

        $this->assertCount(2, $org->links);
        $urls = $org->links->pluck('url')->sort()->values()->all();
        $this->assertSame([
            'https://acme.example.com',
            'https://acme.example.com/careers',
        ], $urls);
        // Polymorphic columns set automatically via morphMany.
        $this->assertSame(Organization::class, $org->links->first()->linkable_type);
    }

    #[Test]
    public function confirming_a_project_with_nested_links_attaches_them(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'Migration',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'links' => [
                [
                    'url' => 'https://github.com/acme/migration',
                    'type' => 'github',
                    'title' => 'Source repo',
                    'description' => 'Repo with the migration code',
                ],
            ],
        ]);

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertCount(1, $project->links);
        $link = $project->links->first();
        $this->assertSame(Project::class, $link->linkable_type);
        $this->assertSame($project->id, $link->linkable_id);
        $this->assertSame('https://github.com/acme/migration', $link->url);
        $this->assertSame('github', $link->type);
        $this->assertSame('Source repo', $link->title);
        $this->assertSame('Repo with the migration code', $link->description);
    }

    #[Test]
    public function confirming_a_position_with_nested_links_attaches_them(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'position', $this->positionPayload([
            'links' => [
                ['url' => 'https://example.com/jd.pdf', 'type' => 'documentation'],
            ],
        ]));

        $position = (new DraftConfirmer())->confirm($draft);

        $this->assertCount(1, $position->links);
        $this->assertSame(Position::class, $position->links->first()->linkable_type);
    }

    #[Test]
    public function confirming_an_accomplishment_with_nested_links_attaches_them(): void
    {
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $project = $this->makeProject($org);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'accomplishment', [
            'project_name' => $project->name,
            'organization_name' => 'Acme',
            'title' => 'Shipped it',
            'description' => 'Description',
            'date' => '2023-01-01',
            'confidence' => 3,
            'prominence' => 3,
            'links' => [
                [
                    'url' => 'https://conference.example.com/talk',
                    'type' => 'talk',
                    'is_personal_appearance' => true,
                ],
            ],
        ]);

        $accomplishment = (new DraftConfirmer())->confirm($draft);

        $this->assertCount(1, $accomplishment->links);
        $link = $accomplishment->links->first();
        $this->assertSame(Accomplishment::class, $link->linkable_type);
        $this->assertSame('talk', $link->type);
        $this->assertTrue($link->is_personal_appearance);
    }

    #[Test]
    public function nested_link_with_invalid_type_defaults_to_other(): void
    {
        // AI emitted a type outside Link::TYPES. The link is still
        // created (the URL is worth preserving) with type = "other"
        // since the column is non-nullable.
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'Migration',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'links' => [
                ['url' => 'https://example.com/something', 'type' => 'whatever'],
            ],
        ]);

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertSame('other', $project->links->first()->type);
    }

    #[Test]
    public function nested_link_without_type_defaults_to_other(): void
    {
        // AI omitted type entirely. Same fallback — the column is
        // non-nullable, so we default to "other".
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'Migration',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'links' => [
                ['url' => 'https://example.com/something'],
            ],
        ]);

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertSame('other', $project->links->first()->type);
    }

    #[Test]
    public function nested_link_without_url_is_skipped(): void
    {
        // Defensive: an entry without a usable url is skipped rather
        // than failing the entire confirmation.
        $org = Organization::create(['name' => 'Acme', 'type' => 'employer']);
        $doc = $this->makeDocument();
        $draft = $this->makeDraft($doc, 'project', [
            'organization_name' => 'Acme',
            'name' => 'Migration',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'links' => [
                ['type' => 'website'],
                ['url' => '   '],
                ['url' => 'https://valid.example.com', 'type' => 'website'],
            ],
        ]);

        $project = (new DraftConfirmer())->confirm($draft);

        $this->assertCount(1, $project->links);
        $this->assertSame('https://valid.example.com', $project->links->first()->url);
    }
}