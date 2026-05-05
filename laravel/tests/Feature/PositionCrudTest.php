<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PositionCrudTest extends TestCase
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
    public function the_create_page_loads_in_the_context_of_an_organization(): void
    {
        $organization = $this->makeOrganization();

        $this->get(route('positions.create', $organization))
            ->assertOk()
            ->assertSee('Add position')
            ->assertSee('At Test Co');
    }

    #[Test]
    public function a_valid_submission_creates_a_position_and_redirects_to_show(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('positions.store'), [
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-15',
        ]);

        $position = Position::where('title', 'Senior Engineer')->first();

        $this->assertNotNull($position);
        $this->assertSame($organization->id, $position->organization_id);
        $response->assertRedirect(route('positions.show', $position));
    }

    #[Test]
    public function submitting_without_required_fields_fails_validation(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('positions.store'), [
            'organization_id' => $organization->id,
            'title' => '',
            'employment_type' => '',
            'location_arrangement' => '',
            'start_date' => '',
        ]);

        $response->assertSessionHasErrors([
            'title',
            'employment_type',
            'location_arrangement',
            'start_date',
        ]);
    }

    #[Test]
    public function end_date_must_be_on_or_after_start_date(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('positions.store'), [
            'organization_id' => $organization->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-06-01',
            'end_date' => '2022-01-01',
        ]);

        $response->assertSessionHasErrors('end_date');
    }

    #[Test]
    public function team_size_inputs_are_normalized_to_strip_thousands_separators(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('positions.store'), [
            'organization_id' => $organization->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
            'team_size_extended' => '1,200',
        ]);

        $this->assertDatabaseHas('positions', [
            'title' => 'Engineer',
            'team_size_extended' => 1200,
        ]);
    }

    #[Test]
    public function reason_for_leaving_notes_are_cleared_when_reason_is_still_employed(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('positions.store'), [
            'organization_id' => $organization->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
            'reason_for_leaving' => 'still_employed',
            'reason_for_leaving_notes' => 'Should be cleared',
        ]);

        $position = Position::where('title', 'Engineer')->first();
        $this->assertNull($position->reason_for_leaving_notes);
    }

    #[Test]
    public function reason_for_leaving_notes_are_cleared_when_reason_is_empty(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('positions.store'), [
            'organization_id' => $organization->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
            'reason_for_leaving' => '',
            'reason_for_leaving_notes' => 'Should be cleared',
        ]);

        $position = Position::where('title', 'Engineer')->first();
        $this->assertNull($position->reason_for_leaving_notes);
    }

    #[Test]
    public function reason_for_leaving_notes_are_preserved_for_other_reasons(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('positions.store'), [
            'organization_id' => $organization->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
            'end_date' => '2024-12-31',
            'reason_for_leaving' => 'laid_off',
            'reason_for_leaving_notes' => 'Round of layoffs in December',
        ]);

        $position = Position::where('title', 'Engineer')->first();
        $this->assertSame('Round of layoffs in December', $position->reason_for_leaving_notes);
    }

    #[Test]
    public function the_show_page_renders_position_details(): void
    {
        $organization = $this->makeOrganization();
        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
            'end_date' => '2024-06-30',
            'mandate' => 'Build the platform team',
        ]);

        $this->get(route('positions.show', $position))
            ->assertOk()
            ->assertSee('Senior Engineer')
            ->assertSee('Build the platform team')
            ->assertSee('Test Co');
    }

    #[Test]
    public function the_show_page_displays_a_current_badge_for_ongoing_positions(): void
    {
        $organization = $this->makeOrganization();
        $current = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2024-01-01',
        ]);

        $this->get(route('positions.show', $current))
            ->assertOk()
            ->assertSee('Current');
    }

    #[Test]
    public function the_edit_page_renders_a_form_pre_filled_with_existing_data(): void
    {
        $organization = $this->makeOrganization();
        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
        ]);

        $this->get(route('positions.edit', $position))
            ->assertOk()
            ->assertSee('Edit position')
            ->assertSee('value="Senior Engineer"', escape: false);
    }

    #[Test]
    public function a_valid_update_modifies_the_position(): void
    {
        $organization = $this->makeOrganization();
        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Old Title',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
        ]);

        $response = $this->put(route('positions.update', $position), [
            'organization_id' => $organization->id,
            'title' => 'New Title',
            'employment_type' => 'full_time',
            'location_arrangement' => 'hybrid',
            'start_date' => '2022-01-01',
        ]);

        $position->refresh();

        $this->assertSame('New Title', $position->title);
        $this->assertSame('hybrid', $position->location_arrangement);
        $response->assertRedirect(route('positions.show', $position));
    }

    #[Test]
    public function a_delete_request_soft_deletes_the_position_and_redirects_to_org_show(): void
    {
        $organization = $this->makeOrganization();
        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'To Delete',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
        ]);

        $response = $this->delete(route('positions.destroy', $position));

        $this->assertSoftDeleted($position);
        $response->assertRedirect(route('organizations.show', $organization->id));
    }

    #[Test]
    public function the_organization_show_page_lists_positions_in_reverse_chronological_order(): void
    {
        $organization = $this->makeOrganization();

        Position::create([
            'organization_id' => $organization->id,
            'title' => 'Older Position',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2020-01-01',
            'end_date' => '2021-12-31',
        ]);

        Position::create([
            'organization_id' => $organization->id,
            'title' => 'Newer Position',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
            'end_date' => '2023-12-31',
        ]);

        $response = $this->get(route('organizations.show', $organization));
        $content = $response->getContent();

        $newerPos = strpos($content, 'Newer Position');
        $olderPos = strpos($content, 'Older Position');

        $this->assertNotFalse($newerPos);
        $this->assertNotFalse($olderPos);
        $this->assertLessThan($olderPos, $newerPos, 'Newer position should appear before older one in reverse chronological order');
    }

    #[Test]
    public function current_positions_appear_above_completed_positions_in_the_list(): void
    {
        $organization = $this->makeOrganization();

        Position::create([
            'organization_id' => $organization->id,
            'title' => 'Completed Recent',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
        ]);

        Position::create([
            'organization_id' => $organization->id,
            'title' => 'Current Older',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2020-01-01',
        ]);

        $response = $this->get(route('organizations.show', $organization));
        $content = $response->getContent();

        $currentPos = strpos($content, 'Current Older');
        $completedPos = strpos($content, 'Completed Recent');

        $this->assertLessThan($completedPos, $currentPos, 'Current positions should appear above completed ones even when older');
    }
}