<?php

namespace Tests\Feature;

use App\Models\Accomplishment;
use App\Models\CareerTheme;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CareerThemeTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);
    }

    private function makeProject(Organization $organization, string $name = 'Test Project'): Project
    {
        return Project::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);
    }

    #[Test]
    public function a_career_theme_can_be_created(): void
    {
        $theme = CareerTheme::create([
            'name' => 'Distributed systems with a privacy bent',
            'description' => 'My career has focused on building systems that respect user privacy at the protocol level',
            'display_order' => 1,
        ]);

        $this->assertSame('Distributed systems with a privacy bent', $theme->name);
        $this->assertSame(1, $theme->display_order);
    }

    #[Test]
    public function display_order_defaults_to_zero(): void
    {
        $theme = CareerTheme::create([
            'name' => 'Some Theme',
        ]);

        $this->assertSame(0, $theme->display_order);
    }

    #[Test]
    public function a_theme_can_link_to_multiple_projects(): void
    {
        $organization = $this->makeOrganization();
        $project1 = $this->makeProject($organization, 'Project A');
        $project2 = $this->makeProject($organization, 'Project B');

        $theme = CareerTheme::create([
            'name' => 'Privacy-focused infrastructure',
        ]);

        $theme->projects()->attach([$project1->id, $project2->id]);

        $this->assertCount(2, $theme->projects);

        // Inverse — projects know their themes
        $this->assertCount(1, $project1->careerThemes);
        $this->assertSame($theme->id, $project1->careerThemes->first()->id);
    }

    #[Test]
    public function a_theme_can_link_to_accomplishments(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Built privacy-preserving feature',
            'date' => '2023-06-01',
        ]);

        $theme = CareerTheme::create([
            'name' => 'Privacy-focused infrastructure',
        ]);

        $theme->accomplishments()->attach($accomplishment);

        $this->assertCount(1, $theme->accomplishments);
        $this->assertCount(1, $accomplishment->careerThemes);
    }

    #[Test]
    public function deleting_a_theme_cascades_through_join_tables(): void
    {
        $organization = $this->makeOrganization();
        $project = $this->makeProject($organization);

        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Some work',
            'date' => '2023-06-01',
        ]);

        $theme = CareerTheme::create(['name' => 'A theme']);

        $theme->projects()->attach($project);
        $theme->accomplishments()->attach($accomplishment);

        $this->assertDatabaseCount('career_theme_projects', 1);
        $this->assertDatabaseCount('career_theme_accomplishments', 1);

        $theme->forceDelete();

        $this->assertDatabaseCount('career_theme_projects', 0);
        $this->assertDatabaseCount('career_theme_accomplishments', 0);

        // Source records themselves are unaffected
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('accomplishments', ['id' => $accomplishment->id]);
    }
}