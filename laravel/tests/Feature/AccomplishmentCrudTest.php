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
            'title' => 'Connection flow',
            'description' => 'Shipped the new connection flow',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'confidence' => 4,
            'prominence' => 5,
        ]);

        $accomplishment = Accomplishment::where('title', 'Connection flow')->first();
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
            'title' => 'Mentorship',
            'description' => 'Mentored two junior engineers',
            'dating_type' => 'period',
            'period_start' => '2023-01-01',
            'period_end' => '2023-12-31',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $accomplishment = Accomplishment::where('title', 'Mentorship')->first();
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
            'title' => 'On-call lead',
            'description' => 'Acting as on-call lead',
            'dating_type' => 'period',
            'period_start' => '2024-06-01',
            'confidence' => 4,
            'prominence' => 3,
        ]);

        $accomplishment = Accomplishment::where('title', 'On-call lead')->first();
        $this->assertNotNull($accomplishment);
        $this->assertNull($accomplishment->period_end);
        $this->assertTrue($accomplishment->isOngoing());
    }

    #[Test]
    public function the_dating_toggle_clears_unused_fields(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'title' => 'Stale field test',
            'description' => 'Testing dating field cleanup',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'period_start' => '2023-01-01',
            'period_end' => '2023-12-31',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $accomplishment = Accomplishment::where('title', 'Stale field test')->first();
        $this->assertNotNull($accomplishment);
        $this->assertNotNull($accomplishment->date);
        $this->assertNull($accomplishment->period_start);
        $this->assertNull($accomplishment->period_end);
    }

    /**
     * Regression test: previously, submitting with no date and no
     * period_start passed form validation, hit the model's
     * validateInvariants, and returned a 500 with an
     * InvalidArgumentException stack trace. The form layer now catches
     * this case and surfaces it as a normal validation error.
     */
    #[Test]
    public function submitting_without_a_date_or_period_returns_a_validation_error_not_a_500(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $response = $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'title' => 'No date test',
            'description' => 'Body without date or period',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $response->assertStatus(302); // redirect-with-errors, not 500
        $response->assertSessionHasErrors('date');
    }

    /**
     * Regression test: bypassing the dating_type radio (e.g., by
     * submitting both date and period_start directly) should be
     * caught at the form layer rather than letting the model's XOR
     * validator throw and surface as a 500.
     */
    #[Test]
    public function submitting_both_a_date_and_period_returns_a_validation_error_not_a_500(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        // We submit without a dating_type so the normalize step doesn't
        // strip one of the values. Both reach the validator and trigger
        // the "not both" rule.
        $response = $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'title' => 'Both date and period',
            'description' => 'Should fail validation',
            'date' => '2023-06-15',
            'period_start' => '2023-01-01',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('date');
    }

    #[Test]
    public function the_impact_trio_is_stored_when_provided(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'title' => 'Latency win',
            'description' => 'Reduced p99 latency',
            'impact_metric' => 'p99 latency',
            'impact_value' => '47',
            'impact_unit' => 'percent reduction',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'confidence' => 4,
            'prominence' => 5,
        ]);

        $accomplishment = Accomplishment::where('title', 'Latency win')->first();
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
            'title' => 'Default scoring',
            'description' => 'Default scoring test',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $accomplishment = Accomplishment::where('title', 'Default scoring')->first();
        $this->assertSame(3, $accomplishment->confidence);
        $this->assertSame(3, $accomplishment->prominence);
    }

    #[Test]
    public function submitting_without_required_fields_fails_validation(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $response = $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'title' => '',
            'description' => '',
            'confidence' => '',
            'prominence' => '',
        ]);

        $response->assertSessionHasErrors([
            'title',
            'description',
            'confidence',
            'prominence',
        ]);
    }

    #[Test]
    public function title_is_required_for_new_accomplishments(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $response = $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'description' => 'Body without a title',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $response->assertSessionHasErrors('title');
        $this->assertNull(Accomplishment::where('description', 'Body without a title')->first());
    }

    #[Test]
    public function title_has_a_max_length_of_120_characters(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $response = $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'title' => str_repeat('a', 121),
            'description' => 'Test',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $response->assertSessionHasErrors('title');
    }

    #[Test]
    public function an_accomplishment_can_be_created_with_string_typed_ids(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $response = $this->post(route('accomplishments.store'), [
            'project_id' => (string) $project->id,
            'title' => 'String ID test',
            'description' => 'Testing string-typed IDs from form submission',
            'dating_type' => 'date',
            'date' => '2023-06-15',
            'confidence' => '3',
            'prominence' => '3',
        ]);

        $accomplishment = Accomplishment::where('title', 'String ID test')->first();
        $this->assertNotNull($accomplishment);
        $response->assertRedirect(route('accomplishments.show', $accomplishment));
    }

    #[Test]
    public function the_show_page_renders_title_as_heading_and_description_below(): void
    {
        $project = $this->makeProject($this->makeOrganization());
        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'title' => 'Connection flow shipped',
            'description' => 'Shipped the new connection flow with reduced setup time',
            'impact_metric' => 'setup time',
            'impact_value' => '93',
            'impact_unit' => 'percent reduction',
            'date' => '2023-06-15',
            'confidence' => 4,
            'prominence' => 5,
        ]);

        $response = $this->get(route('accomplishments.show', $accomplishment));

        $response->assertOk()
            ->assertSee('Connection flow shipped')
            ->assertSee('Shipped the new connection flow with reduced setup time')
            ->assertSee('93')
            ->assertSee('percent reduction')
            ->assertSee('setup time')
            ->assertSee('Confident')
            ->assertSee('Featured');

        $content = $response->getContent();
        $titlePos = strpos($content, 'Connection flow shipped');
        $descPos = strpos($content, 'Shipped the new connection flow');
        $this->assertLessThan($descPos, $titlePos, 'Title should appear above description on show page');
    }

    #[Test]
    public function destroying_a_project_attached_accomplishment_redirects_to_the_project(): void
    {
        $project = $this->makeProject($this->makeOrganization());
        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'title' => 'To delete',
            'description' => 'Will be deleted',
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
            'title' => 'To delete',
            'description' => 'Will be deleted',
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
            'title' => 'First',
            'description' => 'First accomplishment',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        Accomplishment::create([
            'project_id' => $project->id,
            'title' => 'Second',
            'description' => 'Second accomplishment',
            'date' => '2023-08-20',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('First')
            ->assertSee('Second');
    }

    #[Test]
    public function the_position_show_page_lists_only_direct_accomplishments(): void
    {
        $organization = $this->makeOrganization();
        $position = $this->makePosition($organization);
        $project = $this->makeProject($organization, $position);

        Accomplishment::create([
            'position_id' => $position->id,
            'title' => 'Direct',
            'description' => 'Direct accomplishment',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        Accomplishment::create([
            'project_id' => $project->id,
            'title' => 'Project',
            'description' => 'Project accomplishment',
            'date' => '2023-06-15',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        $response = $this->get(route('positions.show', $position));
        $content = $response->getContent();

        $directSectionPos = strpos($content, 'Direct accomplishments');
        $directInDirectSection = strpos($content, 'Direct', $directSectionPos);
        $projectInDirectSection = strpos($content, 'Project accomplishment', $directSectionPos);

        $this->assertNotFalse($directInDirectSection);
        $this->assertFalse($projectInDirectSection);
    }

    #[Test]
    public function ongoing_accomplishments_appear_first_in_lists(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        Accomplishment::create([
            'project_id' => $project->id,
            'title' => 'Completed recent',
            'description' => 'Completed recent',
            'date' => '2024-01-01',
            'confidence' => 3,
            'prominence' => 3,
        ]);

        Accomplishment::create([
            'project_id' => $project->id,
            'title' => 'Ongoing older',
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
            'title' => 'Test',
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