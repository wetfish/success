<?php

namespace Tests\Feature;

use App\Models\Accomplishment;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccomplishmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);
    }

    private function makePosition(Organization $organization): Position
    {
        return Position::create([
            'organization_id' => $organization->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'location_arrangement' => 'remote',
        ]);
    }

    private function makeProject(Organization $organization): Project
    {
        return Project::create([
            'organization_id' => $organization->id,
            'name' => 'Test Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);
    }

    #[Test]
    public function an_accomplishment_belonging_to_a_project_with_a_date_can_be_created(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Shipped feature X',
            'date' => '2023-03-15',
            'impact_metric' => 'p99 latency',
            'impact_value' => '47',
            'impact_unit' => 'percent reduction',
            'confidence' => 4,
            'prominence' => 5,
        ]);

        $this->assertNotNull($accomplishment->id);
        $this->assertTrue($accomplishment->isPointInTime());
        $this->assertFalse($accomplishment->isSpan());
        $this->assertFalse($accomplishment->isOngoing());
    }

    #[Test]
    public function an_accomplishment_belonging_to_a_position_with_a_period_can_be_created(): void
    {
        $position = $this->makePosition($this->makeOrganization());

        $accomplishment = Accomplishment::create([
            'position_id' => $position->id,
            'description' => 'Mentored five engineers',
            'period_start' => '2023-01-01',
            'period_end' => '2024-09-30',
        ]);

        $this->assertTrue($accomplishment->isSpan());
        $this->assertFalse($accomplishment->isPointInTime());
        $this->assertFalse($accomplishment->isOngoing());
    }

    #[Test]
    public function an_ongoing_accomplishment_has_period_start_but_no_period_end(): void
    {
        $position = $this->makePosition($this->makeOrganization());

        $accomplishment = Accomplishment::create([
            'position_id' => $position->id,
            'description' => 'Currently leading the migration to Postgres',
            'period_start' => '2024-06-01',
        ]);

        $this->assertTrue($accomplishment->isOngoing());
        $this->assertTrue($accomplishment->isSpan());
        $this->assertFalse($accomplishment->isPointInTime());
    }

    #[Test]
    public function an_accomplishment_must_belong_to_either_a_project_or_a_position(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Accomplishment::create([
            'description' => 'Orphan accomplishment',
            'date' => '2023-01-01',
        ]);
    }

    #[Test]
    public function an_accomplishment_cannot_belong_to_both_a_project_and_a_position(): void
    {
        $organization = $this->makeOrganization();
        $project = $this->makeProject($organization);
        $position = $this->makePosition($organization);

        $this->expectException(InvalidArgumentException::class);

        Accomplishment::create([
            'project_id' => $project->id,
            'position_id' => $position->id,
            'description' => 'Conflicting accomplishment',
            'date' => '2023-01-01',
        ]);
    }

    #[Test]
    public function an_accomplishment_must_have_either_a_date_or_a_period_start(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->expectException(InvalidArgumentException::class);

        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'No timing info',
        ]);
    }

    #[Test]
    public function an_accomplishment_cannot_have_both_a_date_and_a_period_start(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->expectException(InvalidArgumentException::class);

        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Conflicting timing',
            'date' => '2023-01-01',
            'period_start' => '2023-01-01',
        ]);
    }

    #[Test]
    public function period_end_requires_period_start(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->expectException(InvalidArgumentException::class);

        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Bad timing',
            'date' => '2023-01-01',
            'period_end' => '2023-12-31',
        ]);
    }

    #[Test]
    public function period_end_must_be_on_or_after_period_start(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->expectException(InvalidArgumentException::class);

        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Reversed timing',
            'period_start' => '2024-06-01',
            'period_end' => '2023-01-01',
        ]);
    }

    #[Test]
    public function confidence_must_be_in_the_one_to_five_range(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->expectException(InvalidArgumentException::class);

        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Out of range',
            'date' => '2023-01-01',
            'confidence' => 7,
        ]);
    }

    #[Test]
    public function prominence_must_be_in_the_one_to_five_range(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->expectException(InvalidArgumentException::class);

        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Out of range',
            'date' => '2023-01-01',
            'prominence' => 0,
        ]);
    }

    #[Test]
    public function an_accomplishment_can_have_collaborators(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Pair-programmed feature',
            'date' => '2023-03-15',
        ]);

        $alex = Person::create(['name' => 'Alex']);
        $jordan = Person::create(['name' => 'Jordan']);

        $accomplishment->collaborators()->attach($alex, [
            'role_on_accomplishment' => 'co-author',
        ]);
        $accomplishment->collaborators()->attach($jordan, [
            'role_on_accomplishment' => 'code reviewer',
        ]);

        $this->assertCount(2, $accomplishment->collaborators);

        // Inverse relationship: a person knows their accomplishments
        $this->assertCount(1, $alex->accomplishments);
        $this->assertSame(
            'co-author',
            $alex->accomplishments->first()->pivot->role_on_accomplishment
        );
    }

    #[Test]
    public function deleting_a_project_cascades_to_its_accomplishments(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Some work',
            'date' => '2023-03-15',
        ]);

        $project->forceDelete();

        $this->assertDatabaseCount('accomplishments', 0);
    }
}