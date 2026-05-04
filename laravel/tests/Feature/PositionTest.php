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
    public function a_position_can_have_a_reporting_relationship(): void
    {
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
            'reports_to_person_id' => $manager->id,
        ]);

        $this->assertSame($manager->id, $position->reportsTo->id);
        $this->assertSame('Alex Manager', $position->reportsTo->name);
    }

    #[Test]
    public function force_deleting_a_manager_sets_position_reports_to_null(): void
    {
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
            'reports_to_person_id' => $manager->id,
        ]);

        $manager->forceDelete();
        $position->refresh();

        $this->assertNull($position->reports_to_person_id);
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