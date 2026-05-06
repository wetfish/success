<?php

namespace Tests\Feature;

use App\Models\Accomplishment;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccomplishmentCrudTest extends TestCase
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

    private function makeProject(Organization $organization, ?Position $position = null): Project
    {
        return Project::create([
            'organization_id' => $organization->id,
            'position_id' => $position?->id,
            'name' => 'Test Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);
    }

    #[Test]
    public function the_create_for_project_page_loads(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->get(route('accomplishments.createForProject', $project))
            ->assertOk()
            ->assertSee('Add accomplishment')
            ->assertSee('Test Project');
    }

    #[Test]
    public function the_create_for_position_page_loads(): void
    {
        $position = $this->makePosition($this->makeOrganization());

        $this->get(route('accomplishments.createForPosition', $position))
            ->assertOk()
            ->assertSee('Add accomplishment')
            ->assertSee('Engineer');
    }

    #[Test]
    public function a_valid_project_attached_accomplishment_can_be_created_with_a_single_date(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $response = $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'description' => 'Shipped the new connection flow',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'confidence' => 4,
            'prominence' => 5,
        ]);

        $accomplishment = Accomplishment::where('description', 'Shipped the new connection flow')->first();
        $this->assertNotNull($accomplishment);
        $this->assertSame($project->id, $accomplishment->project_id);
        $this->assertNull($accomplishment->position_id);
        $this->assertSame('2023-06-15', $accomplishment->date->format('Y-m-d'));
        $this->assertNull($accomplishment->period_start);
        $this->assertSame(4, $accomplishment->confidence);
        $this->assertSame(5, $accomplishment->prominence);
        $response->assertRedirect(route('accomplishments.show', $accomplishment));
    }

    #[Test]
    public function a_valid_position_attached_accomplishment_can_be_created_with_a_period(): void
    {
        $position = $this->makePosition($this->makeOrganization());

        $this->post(route('accomplishments.store'), [
            'position_id' => $position->id,
            'description' => 'Mentored two junior engineers',
            'dating_type' => 'period',
            'period_start' => '2023-01-01',
            'period_end' => '2023-12-31',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $accomplishment = Accomplishment::where('description', 'Mentored two junior engineers')->first();
        $this->assertNotNull($accomplishment);
        $this->assertSame($position->id, $accomplishment->position_id);
        $this->assertNull($accomplishment->project_id);
        $this->assertNull($accomplishment->date);
        $this->assertSame('2023-01-01', $accomplishment->period_start->format('Y-m-d'));
        $this->assertSame('2023-12-31', $accomplishment->period_end->format('Y-m-d'));
    }

    #[Test]
    public function an_ongoing_accomplishment_has_no_period_end(): void
    {
        $position = $this->makePosition($this->makeOrganization());

        $this->post(route('accomplishments.store'), [
            'position_id' => $position->id,
            'description' => 'Acting as on-call lead',
            'dating_type' => 'period',
            'period_start' => '2024-06-01',
            'confidence' => 4,
            'prominence' => 3,
        ]);

        $accomplishment = Accomplishment::where('description', 'Acting as on-call lead')->first();
        $this->assertNotNull($accomplishment);
        $this->assertNull($accomplishment->period_end);
        $this->assertTrue($accomplishment->isOngoing());
    }

    #[Test]
    public function the_dating_toggle_clears_unused_fields(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        // Submit with dating_type=date but also include period values —
        // they should be stripped, not retained as data alongside the date.
        $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'description' => 'Stale field test',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'period_start' => '2023-01-01',
            'period_end' => '2023-12-31',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $accomplishment = Accomplishment::where('description', 'Stale field test')->first();
        $this->assertNotNull($accomplishment);
        $this->assertNotNull($accomplishment->date);
        $this->assertNull($accomplishment->period_start);
        $this->assertNull($accomplishment->period_end);
    }

    #[Test]
    public function the_impact_trio_is_stored_when_provided(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'description' => 'Reduced p99 latency',
            'impact_metric' => 'p99 latency',
            'impact_value' => '47',
            'impact_unit' => 'percent reduction',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'confidence' => 4,
            'prominence' => 5,
        ]);

        $accomplishment = Accomplishment::where('description', 'Reduced p99 latency')->first();
        $this->assertSame('p99 latency', $accomplishment->impact_metric);
        $this->assertSame('47', $accomplishment->impact_value);
        $this->assertSame('percent reduction', $accomplishment->impact_unit);
    }

    #[Test]
    public function default_slider_values_of_three_are_accepted(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'description' => 'Default scoring test',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $accomplishment = Accomplishment::where('description', 'Default scoring test')->first();
        $this->assertSame(3, $accomplishment->confidence);
        $this->assertSame(3, $accomplishment->prominence);
    }

    #[Test]
    public function submitting_without_required_fields_fails_validation(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $response = $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'description' => '',
            'confidence' => '',
            'prominence' => '',
        ]);

        $response->assertSessionHasErrors([
            'description',
            'confidence',
            'prominence',
        ]);
    }

    /**
     * Regression test for the same type-comparison class of bug we hit
     * in the Project sub-project flow. Submits IDs as strings (mimicking
     * real form input) and confirms the model validators handle them
     * correctly without false-rejecting.
     */
    #[Test]
    public function an_accomplishment_can_be_created_with_string_typed_ids(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $response = $this->post(route('accomplishments.store'), [
            'project_id' => (string) $project->id,
            'description' => 'String ID test',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'confidence' => '3',
            'prominence' => '3',
        ]);

        $accomplishment = Accomplishment::where('description', 'String ID test')->first();
        $this->assertNotNull($accomplishment);
        $response->assertRedirect(route('accomplishments.show', $accomplishment));
    }

    #[Test]
    public function the_show_page_renders_accomplishment_details(): void
    {
        $project = $this->makeProject($this->makeOrganization());
        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Shipped the connection flow',
            'impact_metric' => 'setup time',
            'impact_value' => '93',
            'impact_unit' => 'percent reduction',
            'date' => '2023-06-15',
            'confidence' => 4,
            'prominence' => 5,
        ]);

        $this->get(route('accomplishments.show', $accomplishment))
            ->assertOk()
            ->assertSee('Shipped the connection flow')
            ->assertSee('93')
            ->assertSee('percent reduction')
            ->assertSee('setup time')
            ->assertSee('Confident') // confidence label for value 4
            ->assertSee('Featured'); // prominence label for value 5
    }

    #[Test]
    public function destroying_a_project_attached_accomplishment_redirects_to_the_project(): void
    {
        $project = $this->makeProject($this->makeOrganization());
        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'To delete',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $response = $this->delete(route('accomplishments.destroy', $accomplishment));

        $this->assertSoftDeleted($accomplishment);
        $response->assertRedirect(route('projects.show', $project->id));
    }

    #[Test]
    public function destroying_a_position_attached_accomplishment_redirects_to_the_position(): void
    {
        $position = $this->makePosition($this->makeOrganization());
        $accomplishment = Accomplishment::create([
            'position_id' => $position->id,
            'description' => 'To delete',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $response = $this->delete(route('accomplishments.destroy', $accomplishment));

        $this->assertSoftDeleted($accomplishment);
        $response->assertRedirect(route('positions.show', $position->id));
    }

    #[Test]
    public function the_project_show_page_lists_its_accomplishments(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'First accomplishment',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Second accomplishment',
            'date' => '2023-08-20',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('First accomplishment')
            ->assertSee('Second accomplishment');
    }

    #[Test]
    public function the_position_show_page_lists_only_direct_accomplishments(): void
    {
        $organization = $this->makeOrganization();
        $position = $this->makePosition($organization);
        $project = $this->makeProject($organization, $position);

        // Direct on position
        Accomplishment::create([
            'position_id' => $position->id,
            'description' => 'Direct accomplishment',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        // Under a project (not directly on position)
        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Project accomplishment',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $response = $this->get(route('positions.show', $position));
        $content = $response->getContent();

        // Only the direct one should appear in the position's
        // "Direct accomplishments" section.
        $directSectionPos = strpos($content, 'Direct accomplishments');
        $directInDirectSection = strpos($content, 'Direct accomplishment', $directSectionPos);
        $projectInDirectSection = strpos($content, 'Project accomplishment', $directSectionPos);

        $this->assertNotFalse($directInDirectSection);
        $this->assertFalse($projectInDirectSection);
    }

    #[Test]
    public function ongoing_accomplishments_appear_first_in_lists(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        // Completed accomplishment, more recent
        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Completed recent',
            'date' => '2024-01-01',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        // Ongoing accomplishment, older start
        Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Ongoing older',
            'period_start' => '2022-01-01',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $response = $this->get(route('projects.show', $project));
        $content = $response->getContent();

        $ongoingPos = strpos($content, 'Ongoing older');
        $completedPos = strpos($content, 'Completed recent');

        $this->assertLessThan(
            $completedPos,
            $ongoingPos,
            'Ongoing accomplishments should appear above completed ones even when older'
        );
    }

    #[Test]
    public function organization_show_page_displays_accomplishment_count_for_position(): void
    {
        $organization = $this->makeOrganization();
        $position = $this->makePosition($organization);

        Accomplishment::create([
            'position_id' => $position->id,
            'description' => 'Test accomplishment',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $this->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('1 accomplishment');
    }
}