<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end tests for the Organization CRUD UI.
 *
 * These tests exercise the full HTTP request/response cycle: routes,
 * controllers, form requests, and views. They complement the model-level
 * tests in OrganizationTest by verifying the user-facing layer works.
 */
class OrganizationCrudTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_root_url_redirects_to_the_organization_index(): void
    {
        $this->get('/')->assertRedirect(route('organizations.index'));
    }

    #[Test]
    public function the_index_page_loads_with_an_empty_state_when_no_organizations_exist(): void
    {
        $this->get(route('organizations.index'))
            ->assertOk()
            ->assertSee('No organizations yet')
            ->assertSee('Add your first organization');
    }

    #[Test]
    public function the_index_page_lists_existing_organizations(): void
    {
        Organization::create(['name' => 'Lightning Labs', 'type' => 'employer']);
        Organization::create(['name' => 'Casa', 'type' => 'employer']);

        $this->get(route('organizations.index'))
            ->assertOk()
            ->assertSee('Lightning Labs')
            ->assertSee('Casa')
            ->assertDontSee('No organizations yet');
    }

    #[Test]
    public function the_create_page_renders_an_empty_form(): void
    {
        $this->get(route('organizations.create'))
            ->assertOk()
            ->assertSee('Add organization')
            ->assertSee('name="name"', escape: false)
            ->assertSee('name="type"', escape: false);
    }

    #[Test]
    public function a_valid_submission_creates_an_organization_and_redirects_to_its_detail_page(): void
    {
        $response = $this->post(route('organizations.store'), [
            'name' => 'Lightning Labs',
            'type' => 'employer',
            'website' => 'https://lightning.engineering',
            'founded_year' => '2017',
        ]);

        $organization = Organization::where('name', 'Lightning Labs')->first();

        $this->assertNotNull($organization);
        $this->assertSame('employer', $organization->type);
        $this->assertSame(2017, $organization->founded_year);

        $response->assertRedirect(route('organizations.show', $organization));
        $response->assertSessionHas('status');
    }

    #[Test]
    public function the_founded_year_input_is_normalized_to_strip_thousands_separators(): void
    {
        $this->post(route('organizations.store'), [
            'name' => 'Old Co',
            'type' => 'employer',
            'founded_year' => '1,997',
        ]);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Old Co',
            'founded_year' => 1997,
        ]);
    }

    #[Test]
    public function empty_optional_fields_are_persisted_as_null(): void
    {
        $this->post(route('organizations.store'), [
            'name' => 'Minimal Co',
            'type' => 'employer',
            'website' => '',
            'tagline' => '',
            'description' => '',
        ]);

        $organization = Organization::where('name', 'Minimal Co')->first();

        $this->assertNotNull($organization);
        $this->assertNull($organization->website);
        $this->assertNull($organization->tagline);
        $this->assertNull($organization->description);
    }

    #[Test]
    public function submitting_without_a_name_fails_validation(): void
    {
        $response = $this->post(route('organizations.store'), [
            'name' => '',
            'type' => 'employer',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Organization::count());
    }

    #[Test]
    public function submitting_an_invalid_type_fails_validation(): void
    {
        $response = $this->post(route('organizations.store'), [
            'name' => 'Test',
            'type' => 'not_a_real_type',
        ]);

        $response->assertSessionHasErrors('type');
    }

    #[Test]
    public function submitting_an_invalid_url_for_website_fails_validation(): void
    {
        $response = $this->post(route('organizations.store'), [
            'name' => 'Test',
            'type' => 'employer',
            'website' => 'not a url',
        ]);

        $response->assertSessionHasErrors('website');
    }

    #[Test]
    public function the_show_page_renders_organization_details(): void
    {
        $organization = Organization::create([
            'name' => 'Lightning Labs',
            'type' => 'employer',
            'tagline' => 'Faster, cheaper, global layer-two bitcoin',
            'headquarters' => 'NYC',
            'founded_year' => 2017,
        ]);

        $this->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Lightning Labs')
            ->assertSee('Faster, cheaper, global layer-two bitcoin')
            ->assertSee('NYC')
            ->assertSee('2017');
    }

    #[Test]
    public function the_edit_page_renders_a_form_pre_filled_with_existing_data(): void
    {
        $organization = Organization::create([
            'name' => 'Lightning Labs',
            'type' => 'employer',
        ]);

        $this->get(route('organizations.edit', $organization))
            ->assertOk()
            ->assertSee('Edit organization')
            ->assertSee('value="Lightning Labs"', escape: false);
    }

    #[Test]
    public function a_valid_update_modifies_the_organization_and_redirects_to_show(): void
    {
        $organization = Organization::create([
            'name' => 'Old Name',
            'type' => 'employer',
        ]);

        $response = $this->put(route('organizations.update', $organization), [
            'name' => 'New Name',
            'type' => 'employer',
            'tagline' => 'Updated',
        ]);

        $organization->refresh();

        $this->assertSame('New Name', $organization->name);
        $this->assertSame('Updated', $organization->tagline);

        $response->assertRedirect(route('organizations.show', $organization));
    }

    #[Test]
    public function a_delete_request_soft_deletes_the_organization_and_redirects_to_index(): void
    {
        $organization = Organization::create([
            'name' => 'To Be Deleted',
            'type' => 'employer',
        ]);

        $response = $this->delete(route('organizations.destroy', $organization));

        $this->assertSoftDeleted($organization);
        $response->assertRedirect(route('organizations.index'));
    }

    #[Test]
    public function deleted_organizations_no_longer_appear_in_the_index(): void
    {
        $kept = Organization::create(['name' => 'Kept', 'type' => 'employer']);
        $deleted = Organization::create(['name' => 'Deleted', 'type' => 'employer']);

        $deleted->delete();

        $this->get(route('organizations.index'))
            ->assertOk()
            ->assertSee('Kept')
            ->assertDontSee('Deleted');
    }

    #[Test]
    public function visiting_a_soft_deleted_organization_returns_404(): void
    {
        $organization = Organization::create(['name' => 'Deleted', 'type' => 'employer']);
        $organization->delete();

        $this->get(route('organizations.show', $organization))->assertNotFound();
    }
}