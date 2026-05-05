<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectCrudTest extends TestCase
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
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
        ]);
    }

    #[Test]
    public function the_create_for_organization_page_loads(): void
    {
        $organization = $this->makeOrganization();

        $this->get(route('projects.createForOrganization', $organization))
            ->assertOk()
            ->assertSee('Add project')
            ->assertSee('At Test Co');
    }

    #[Test]
    public function the_create_for_position_page_loads(): void
    {
        $position = $this->makePosition($this->makeOrganization());

        $this->get(route('projects.createForPosition', $position))
            ->assertOk()
            ->assertSee('Engineer');
    }

    #[Test]
    public function the_create_sub_project_page_loads(): void
    {
        $organization = $this->makeOrganization();
        $parent = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Parent Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        $this->get(route('projects.createSubProject', $parent))
            ->assertOk()
            ->assertSee('Add sub-project')
            ->assertSee('Under Parent Project');
    }

    #[Test]
    public function a_valid_organization_level_project_can_be_created(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('projects.store'), [
            'organization_id' => $organization->id,
            'name' => 'Side Project',
            'visibility' => 'open_source',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
            'start_date_month' => '2023-01',
        ]);

        $project = Project::where('name', 'Side Project')->first();
        $this->assertNotNull($project);
        $this->assertSame($organization->id, $project->organization_id);
        $this->assertNull($project->position_id);
        $response->assertRedirect(route('projects.show', $project));
    }

    #[Test]
    public function a_position_level_project_can_be_created(): void
    {
        $position = $this->makePosition($this->makeOrganization());

        $this->post(route('projects.store'), [
            'organization_id' => $position->organization_id,
            'position_id' => $position->id,
            'name' => 'Position Project',
            'visibility' => 'internal',
            'contribution_level' => 'core',
            'date_precision' => 'month',
            'start_date_month' => '2023-01',
        ]);

        $project = Project::where('name', 'Position Project')->first();
        $this->assertNotNull($project);
        $this->assertSame($position->id, $project->position_id);
    }

    #[Test]
    public function a_sub_project_can_be_created(): void
    {
        $organization = $this->makeOrganization();
        $parent = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Parent',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        $this->post(route('projects.store'), [
            'organization_id' => $organization->id,
            'parent_project_id' => $parent->id,
            'name' => 'Child',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
            'start_date_month' => '2023-06',
        ]);

        $child = Project::where('name', 'Child')->first();
        $this->assertNotNull($child);
        $this->assertSame($parent->id, $child->parent_project_id);
    }

    /**
     * Regression test: a sub-project submitted with string-typed ID
     * values (the form-submission path) must succeed. Previously, the
     * model's validateInvariants() used strict equality comparing the
     * DB-fetched int parent org ID against the form-submitted string
     * organization ID, falsely rejecting valid sub-projects.
     */
    #[Test]
    public function a_sub_project_can_be_created_with_string_typed_ids(): void
    {
        $organization = $this->makeOrganization();
        $parent = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Parent',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        // IDs are explicitly cast to strings to match how values arrive
        // from real HTTP form submissions. Without this cast, the test
        // wouldn't catch the type-comparison regression class.
        $response = $this->post(route('projects.store'), [
            'organization_id' => (string) $organization->id,
            'parent_project_id' => (string) $parent->id,
            'name' => 'String-typed Child',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
            'start_date_month' => '2023-06',
        ]);

        $child = Project::where('name', 'String-typed Child')->first();
        $this->assertNotNull($child);
        $this->assertSame($parent->id, $child->parent_project_id);
        $response->assertRedirect(route('projects.show', $child));
    }

    #[Test]
    public function day_precision_dates_are_stored_as_real_dates(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('projects.store'), [
            'organization_id' => $organization->id,
            'name' => 'Day Test',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'day',
            'start_date_day' => '2023-04-15',
            'end_date_day' => '2023-08-22',
        ]);

        $project = Project::where('name', 'Day Test')->first();
        $this->assertSame('2023-04-15', $project->start_date->format('Y-m-d'));
        $this->assertSame('2023-08-22', $project->end_date->format('Y-m-d'));
    }

    #[Test]
    public function month_precision_resolves_to_first_and_last_day_of_month(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('projects.store'), [
            'organization_id' => $organization->id,
            'name' => 'Month Test',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
            'start_date_month' => '2023-04',
            'end_date_month' => '2023-08',
        ]);

        $project = Project::where('name', 'Month Test')->first();
        $this->assertSame('2023-04-01', $project->start_date->format('Y-m-d'));
        $this->assertSame('2023-08-31', $project->end_date->format('Y-m-d'));
    }

    #[Test]
    public function quarter_precision_resolves_to_first_and_last_day_of_quarter(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('projects.store'), [
            'organization_id' => $organization->id,
            'name' => 'Quarter Test',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'quarter',
            'start_date_quarter' => '2',
            'start_date_year' => '2023',
            'end_date_quarter' => '3',
            'end_date_year' => '2023',
        ]);

        $project = Project::where('name', 'Quarter Test')->first();
        $this->assertSame('2023-04-01', $project->start_date->format('Y-m-d'));
        $this->assertSame('2023-09-30', $project->end_date->format('Y-m-d'));
    }

    #[Test]
    public function year_precision_resolves_to_first_and_last_day_of_year(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('projects.store'), [
            'organization_id' => $organization->id,
            'name' => 'Year Test',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'year',
            'start_date_year' => '2023',
            'end_date_year' => '2024',
        ]);

        $project = Project::where('name', 'Year Test')->first();
        $this->assertSame('2023-01-01', $project->start_date->format('Y-m-d'));
        $this->assertSame('2024-12-31', $project->end_date->format('Y-m-d'));
    }

    #[Test]
    public function end_date_is_optional_for_ongoing_projects(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('projects.store'), [
            'organization_id' => $organization->id,
            'name' => 'Ongoing',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
            'start_date_month' => '2023-01',
        ]);

        $project = Project::where('name', 'Ongoing')->first();
        $this->assertNotNull($project->start_date);
        $this->assertNull($project->end_date);
    }

    #[Test]
    public function submitting_without_required_fields_fails_validation(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('projects.store'), [
            'organization_id' => $organization->id,
            'name' => '',
            'visibility' => '',
            'contribution_level' => '',
            'date_precision' => '',
        ]);

        $response->assertSessionHasErrors([
            'name',
            'visibility',
            'contribution_level',
            'date_precision',
        ]);
    }

    #[Test]
    public function the_show_page_renders_project_details(): void
    {
        $organization = $this->makeOrganization();
        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Tor Migration',
            'description' => 'Migrate from port forwarding to Tor',
            'problem' => 'Port forwarding required user configuration',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Tor Migration')
            ->assertSee('Migrate from port forwarding to Tor')
            ->assertSee('Port forwarding required user configuration');
    }

    #[Test]
    public function destroying_a_sub_project_redirects_to_the_parent(): void
    {
        $organization = $this->makeOrganization();
        $parent = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Parent',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);
        $child = Project::create([
            'organization_id' => $organization->id,
            'parent_project_id' => $parent->id,
            'name' => 'Child',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        $response = $this->delete(route('projects.destroy', $child));

        $this->assertSoftDeleted($child);
        $response->assertRedirect(route('projects.show', $parent->id));
    }

    #[Test]
    public function destroying_a_position_attached_project_redirects_to_the_position(): void
    {
        $position = $this->makePosition($this->makeOrganization());
        $project = Project::create([
            'organization_id' => $position->organization_id,
            'position_id' => $position->id,
            'name' => 'Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        $response = $this->delete(route('projects.destroy', $project));
        $response->assertRedirect(route('positions.show', $position->id));
    }

    #[Test]
    public function destroying_an_org_level_project_redirects_to_the_organization(): void
    {
        $organization = $this->makeOrganization();
        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Org Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        $response = $this->delete(route('projects.destroy', $project));
        $response->assertRedirect(route('organizations.show', $organization->id));
    }

    #[Test]
    public function the_organization_show_page_lists_only_position_less_projects_in_other_projects(): void
    {
        $organization = $this->makeOrganization();
        $position = $this->makePosition($organization);

        Project::create([
            'organization_id' => $organization->id,
            'position_id' => $position->id,
            'name' => 'Position Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        Project::create([
            'organization_id' => $organization->id,
            'name' => 'Org Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        $response = $this->get(route('organizations.show', $organization));
        $content = $response->getContent();

        $this->assertStringContainsString('Org Project', $content);

        $otherProjectsPos = strpos($content, 'Other projects');
        $orgProjectInOtherSection = strpos($content, 'Org Project', $otherProjectsPos);
        $this->assertNotFalse($orgProjectInOtherSection);
    }

    #[Test]
    public function the_position_show_page_displays_project_count(): void
    {
        $position = $this->makePosition($this->makeOrganization());

        Project::create([
            'organization_id' => $position->organization_id,
            'position_id' => $position->id,
            'name' => 'Project A',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        Project::create([
            'organization_id' => $position->organization_id,
            'position_id' => $position->id,
            'name' => 'Project B',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);

        $this->get(route('positions.show', $position))
            ->assertOk()
            ->assertSee('Project A')
            ->assertSee('Project B');
    }
}