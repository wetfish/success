<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Person;
use App\Models\Position;
use App\Models\Project;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PositionTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);
    }

    #[Test]
    public function a_position_can_be_created(): void
    {
        $organization = $this->makeOrganization();

        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'end_date' => '2023-06-30',
            'location_arrangement' => 'remote',
            'team_name' => 'Platform',
            'team_size_immediate' => 5,
        ]);

        $this->assertDatabaseHas('positions', [
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
        ]);
        $this->assertSame(5, $position->team_size_immediate);
    }

    #[Test]
    public function a_position_belongs_to_an_organization(): void
    {
        $organization = $this->makeOrganization();

        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'location_arrangement' => 'remote',
        ]);

        $this->assertSame($organization->id, $position->organization->id);
    }

    #[Test]
    public function a_position_can_have_a_manager_via_collaborators(): void
    {
        // Manager relationships now live in the position_collaborators
        // pivot with role_on_position = "Manager", not as a dedicated
        // FK on positions. See the people-schema convergence migration.
        $organization = $this->makeOrganization();

        $manager = Person::create([
            'name' => 'Alex Manager',
            'relationship_type' => 'manager',
        ]);

        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'location_arrangement' => 'remote',
        ]);

        $position->collaborators()->attach($manager, ['role_on_position' => 'Manager']);

        $position->refresh();
        $this->assertCount(1, $position->collaborators);
        $this->assertSame($manager->id, $position->collaborators->first()->id);
        $this->assertSame('Manager', $position->collaborators->first()->pivot->role_on_position);
    }

    #[Test]
    public function force_deleting_a_collaborator_removes_their_pivot_row(): void
    {
        // The position_collaborators FK on person_id cascades on delete,
        // so force-deleting a person also wipes their pivot rows. The
        // position itself remains intact — only the relationship goes
        // away, which is the correct semantics.
        $organization = $this->makeOrganization();

        $manager = Person::create([
            'name' => 'Alex Manager',
            'relationship_type' => 'manager',
        ]);

        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'location_arrangement' => 'remote',
        ]);

        $position->collaborators()->attach($manager, ['role_on_position' => 'Manager']);
        $this->assertDatabaseCount('position_collaborators', 1);

        $manager->forceDelete();

        $this->assertDatabaseCount('position_collaborators', 0);
        $this->assertNotNull($position->fresh()); // position survives
    }

    #[Test]
    public function a_position_can_have_projects(): void
    {
        $organization = $this->makeOrganization();

        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'location_arrangement' => 'remote',
        ]);

        Project::create([
            'organization_id' => $organization->id,
            'position_id' => $position->id,
            'name' => 'Important Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $this->assertCount(1, $position->projects);
    }

    #[Test]
    public function a_position_can_have_links_and_tags(): void
    {
        $organization = $this->makeOrganization();

        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'location_arrangement' => 'remote',
        ]);

        $tag = Tag::create([
            'name' => 'Leadership',
            'category' => 'concept',
        ]);

        $position->links()->create([
            'type' => 'documentation',
            'url' => 'https://internal.example.com/role-doc',
        ]);

        $position->tags()->attach($tag);

        $this->assertCount(1, $position->links);
        $this->assertCount(1, $position->tags);
    }

    #[Test]
    public function force_deleting_an_organization_cascades_to_positions(): void
    {
        $organization = $this->makeOrganization();

        Position::create([
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'location_arrangement' => 'remote',
        ]);

        $organization->forceDelete();

        $this->assertDatabaseCount('positions', 0);
    }
}