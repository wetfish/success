<?php

namespace Tests\Feature;

use App\Models\Accomplishment;
use App\Models\Link;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end tests for the Link CRUD UI.
 *
 * Links are polymorphic — these tests exercise the full request cycle
 * for each parent type: routes, form requests, controller resolution
 * of the linkable alias to a real model, polymorphic relationship
 * persistence, and the redirect-to-parent contract for store, update,
 * and destroy.
 *
 * Model-level invariants (URL required except for internal_doc, etc.)
 * are also tested in `LinkTest`. The duplication is deliberate — that
 * suite verifies the model layer in isolation, this one verifies the
 * model invariants surface as friendly validation errors through the
 * form layer.
 */
class LinkCrudTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrganization(string $name = 'Test Co'): Organization
    {
        return Organization::create(['name' => $name, 'type' => 'employer']);
    }

    private function makePosition(?Organization $organization = null): Position
    {
        return Position::create([
            'organization_id' => ($organization ?? $this->makeOrganization())->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
        ]);
    }

    private function makeProject(?Organization $organization = null): Project
    {
        return Project::create([
            'organization_id' => ($organization ?? $this->makeOrganization())->id,
            'name' => 'Test Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
        ]);
    }

    private function makeAccomplishment(?Project $project = null): Accomplishment
    {
        return Accomplishment::create([
            'project_id' => ($project ?? $this->makeProject())->id,
            'title' => 'Test Accomplishment',
            'description' => 'A thing I did',
            'date' => '2023-01-01',
            'confidence' => 3,
            'prominence' => 3,
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // Create-in-context page loads
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_create_for_organization_page_loads(): void
    {
        $organization = $this->makeOrganization();

        $this->get(route('links.createForOrganization', $organization))
            ->assertOk()
            ->assertSee('Add link')
            ->assertSee('Test Co');
    }

    #[Test]
    public function the_create_for_project_page_loads(): void
    {
        $project = $this->makeProject();

        $this->get(route('links.createForProject', $project))
            ->assertOk()
            ->assertSee('Add link')
            ->assertSee('Test Project');
    }

    #[Test]
    public function the_create_for_position_page_loads(): void
    {
        $position = $this->makePosition();

        $this->get(route('links.createForPosition', $position))
            ->assertOk()
            ->assertSee('Add link')
            ->assertSee('Engineer');
    }

    #[Test]
    public function the_create_for_accomplishment_page_loads(): void
    {
        $accomplishment = $this->makeAccomplishment();

        $this->get(route('links.createForAccomplishment', $accomplishment))
            ->assertOk()
            ->assertSee('Add link')
            ->assertSee('Test Accomplishment');
    }

    // ────────────────────────────────────────────────────────────
    // Type filtering on create pages
    //
    // The dropdown options are scoped to context-appropriate types
    // per LinkRules::TYPES_BY_LINKABLE. Assertions probe the select
    // option values via raw HTML so we know whether a given type slug
    // appears as an option vs. just appearing somewhere else on the
    // page.
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_organization_create_form_offers_org_applicable_types(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->get(route('links.createForOrganization', $organization));

        $response->assertSee('value="slack"', escape: false);
        $response->assertSee('value="careers"', escape: false);
        $response->assertDontSee('value="repo"', escape: false);
        $response->assertDontSee('value="live_demo"', escape: false);
    }

    #[Test]
    public function the_accomplishment_create_form_offers_accomplishment_applicable_types(): void
    {
        $accomplishment = $this->makeAccomplishment();

        $response = $this->get(route('links.createForAccomplishment', $accomplishment));

        $response->assertSee('value="media_appearance"', escape: false);
        $response->assertSee('value="repo"', escape: false);
        $response->assertDontSee('value="slack"', escape: false);
        $response->assertDontSee('value="careers"', escape: false);
    }

    // ────────────────────────────────────────────────────────────
    // Polymorphic store
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function a_valid_organization_link_can_be_created(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $this->assertSame(1, Link::count());
        $link = Link::first();

        // The form alias 'organization' is mapped to the model's
        // fully-qualified class name before storage; the polymorphic
        // relationship resolves to an Organization instance.
        $this->assertSame(Organization::class, $link->linkable_type);
        $this->assertSame($organization->id, $link->linkable_id);
        $this->assertInstanceOf(Organization::class, $link->linkable);

        $this->assertSame('website', $link->type);
        $this->assertSame('https://example.com', $link->url);

        $response->assertRedirect(route('organizations.show', $organization));
        $response->assertSessionHas('status');
    }

    #[Test]
    public function a_valid_project_link_can_be_created(): void
    {
        $project = $this->makeProject();

        $this->post(route('links.store'), [
            'linkable_type' => 'project',
            'linkable_id' => $project->id,
            'type' => 'repo',
            'url' => 'https://github.com/example/repo',
        ])->assertRedirect(route('projects.show', $project));

        $link = Link::first();
        $this->assertInstanceOf(Project::class, $link->linkable);
        $this->assertSame($project->id, $link->linkable->id);
    }

    #[Test]
    public function a_valid_position_link_can_be_created(): void
    {
        $position = $this->makePosition();

        $this->post(route('links.store'), [
            'linkable_type' => 'position',
            'linkable_id' => $position->id,
            'type' => 'talk',
            'url' => 'https://example.com/conf-talk',
            'title' => 'My conference talk',
        ])->assertRedirect(route('positions.show', $position));

        $link = Link::first();
        $this->assertInstanceOf(Position::class, $link->linkable);
    }

    #[Test]
    public function a_valid_accomplishment_link_can_be_created(): void
    {
        $accomplishment = $this->makeAccomplishment();

        $this->post(route('links.store'), [
            'linkable_type' => 'accomplishment',
            'linkable_id' => $accomplishment->id,
            'type' => 'media_appearance',
            'url' => 'https://youtube.com/watch?v=abc',
        ])->assertRedirect(route('accomplishments.show', $accomplishment));

        $link = Link::first();
        $this->assertInstanceOf(Accomplishment::class, $link->linkable);
    }

    #[Test]
    public function a_link_can_be_created_with_string_typed_ids(): void
    {
        // Form submissions arrive as strings; this guards against any
        // type-coercion bugs in the linkable_id flow.
        $organization = $this->makeOrganization();

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => (string) $organization->id,
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $this->assertSame(1, Link::count());
        $response->assertRedirect(route('organizations.show', $organization));
    }

    #[Test]
    public function optional_fields_are_persisted_when_provided(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'website',
            'url' => 'https://example.com',
            'title' => 'Their main site',
            'description' => 'The official homepage',
            'date' => '2024-03-15',
        ]);

        $link = Link::first()->refresh();
        $this->assertSame('Their main site', $link->title);
        $this->assertSame('The official homepage', $link->description);
        $this->assertSame('2024-03-15', $link->date->format('Y-m-d'));
    }

    #[Test]
    public function empty_optional_fields_are_persisted_as_null(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'website',
            'url' => 'https://example.com',
            'title' => '',
            'description' => '',
            'date' => '',
        ]);

        $link = Link::first();
        $this->assertNull($link->title);
        $this->assertNull($link->description);
        $this->assertNull($link->date);
    }

    // ────────────────────────────────────────────────────────────
    // Validation — linkable
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function an_invalid_linkable_type_alias_is_rejected(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'something_invalid',
            'linkable_id' => $organization->id,
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $response->assertSessionHasErrors('linkable_type');
        $this->assertSame(0, Link::count());
    }

    #[Test]
    public function a_fully_qualified_class_name_is_not_accepted_as_linkable_type(): void
    {
        // The form layer takes the short alias only — sending the FQCN
        // (which is what gets stored in the DB) should not be accepted.
        $organization = $this->makeOrganization();

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'App\\Models\\Organization',
            'linkable_id' => $organization->id,
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $response->assertSessionHasErrors('linkable_type');
    }

    #[Test]
    public function a_nonexistent_linkable_id_returns_404(): void
    {
        // Validation accepts any integer for linkable_id (the cross-
        // table check happens in the controller via findOrFail). A
        // bogus ID surfaces as a 404 rather than a validation error,
        // which is appropriate since users can't legitimately reach
        // this case through the UI.
        $response = $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => 99999,
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, Link::count());
    }

    // ────────────────────────────────────────────────────────────
    // Validation — type-conditional URL and title rules
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function non_internal_doc_types_require_a_url(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'website',
            'url' => '',
        ]);

        $response->assertSessionHasErrors('url');
        $this->assertSame(0, Link::count());
    }

    #[Test]
    public function internal_doc_type_allows_a_null_url(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'internal_doc',
            'url' => '',
            'title' => 'Confidential Architecture Doc',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(1, Link::count());
        $this->assertNull(Link::first()->url);
    }

    #[Test]
    public function internal_doc_type_requires_a_title(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'internal_doc',
            'url' => '',
            'title' => '',
        ]);

        $response->assertSessionHasErrors('title');
        $this->assertSame(0, Link::count());
    }

    #[Test]
    public function non_internal_doc_types_do_not_require_a_title(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'website',
            'url' => 'https://example.com',
            'title' => '',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(1, Link::count());
        $this->assertNull(Link::first()->title);
    }

    #[Test]
    public function an_invalid_type_value_is_rejected(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'not_a_real_type',
            'url' => 'https://example.com',
        ]);

        $response->assertSessionHasErrors('type');
    }

    #[Test]
    public function a_malformed_url_is_rejected(): void
    {
        $organization = $this->makeOrganization();

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'website',
            'url' => 'not a url',
        ]);

        $response->assertSessionHasErrors('url');
    }

    #[Test]
    public function a_url_longer_than_255_chars_is_rejected(): void
    {
        // Matches the varchar(255) column size — see chunk 1 discussion
        // of "no known error states from validation/column mismatches."
        $organization = $this->makeOrganization();
        $longUrl = 'https://example.com/' . str_repeat('a', 236);
        $this->assertSame(256, strlen($longUrl));

        $response = $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'website',
            'url' => $longUrl,
        ]);

        $response->assertSessionHasErrors('url');
    }

    // ────────────────────────────────────────────────────────────
    // is_personal_appearance — checkbox normalization
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function an_unchecked_personal_appearance_checkbox_persists_as_false(): void
    {
        // Browsers omit unchecked checkboxes from the request body
        // entirely; LinkRules::normalize() coerces the missing field
        // to a hard false rather than letting it be undefined.
        $organization = $this->makeOrganization();

        $this->post(route('links.store'), [
            'linkable_type' => 'organization',
            'linkable_id' => $organization->id,
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $link = Link::first()->refresh();
        $this->assertFalse($link->is_personal_appearance);
        $this->assertIsBool($link->is_personal_appearance);
    }

    #[Test]
    public function a_checked_personal_appearance_checkbox_persists_as_true(): void
    {
        $project = $this->makeProject();

        $this->post(route('links.store'), [
            'linkable_type' => 'project',
            'linkable_id' => $project->id,
            'type' => 'talk',
            'url' => 'https://example.com/talk',
            'is_personal_appearance' => '1',
        ]);

        $link = Link::first()->refresh();
        $this->assertTrue($link->is_personal_appearance);
    }

    // ────────────────────────────────────────────────────────────
    // Edit
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_edit_page_loads_with_current_values_pre_filled(): void
    {
        $organization = $this->makeOrganization();
        $link = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
            'title' => 'Example Site',
        ]);

        $this->get(route('links.edit', $link))
            ->assertOk()
            ->assertSee('Edit link')
            ->assertSee('value="https://example.com"', escape: false)
            ->assertSee('value="Example Site"', escape: false);
    }

    #[Test]
    public function the_edit_form_does_not_render_hidden_parent_inputs(): void
    {
        // Defense in depth: even though UpdateLinkRequest strips
        // linkable_type/linkable_id, the form template doesn't
        // render them at all so the DOM stays honest.
        $organization = $this->makeOrganization();
        $link = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $this->get(route('links.edit', $link))
            ->assertOk()
            ->assertDontSee('name="linkable_type"', escape: false)
            ->assertDontSee('name="linkable_id"', escape: false);
    }

    #[Test]
    public function the_edit_page_shows_the_correct_parent_breadcrumb(): void
    {
        $organization = $this->makeOrganization('Lightning Labs');
        $link = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $this->get(route('links.edit', $link))
            ->assertOk()
            ->assertSee('Lightning Labs');
    }

    #[Test]
    public function the_edit_form_includes_current_type_even_if_not_on_applicable_list(): void
    {
        // `slack` is applicable to organizations but NOT to accomplishments.
        // If a link of type=slack somehow exists on an accomplishment (set
        // via the AI pipeline or direct DB manipulation), the edit form
        // must still include `slack` as an option so the user sees the
        // current value selected and can edit other fields without being
        // forced to change the type.
        $accomplishment = $this->makeAccomplishment();
        $link = $accomplishment->links()->create([
            'type' => 'slack',
            'url' => 'https://example.slack.com',
            'title' => 'Slack invite',
        ]);

        $this->get(route('links.edit', $link))
            ->assertOk()
            ->assertSee('value="slack"', escape: false);
    }

    // ────────────────────────────────────────────────────────────
    // Update
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function a_valid_update_modifies_the_link_and_redirects_to_parent(): void
    {
        $organization = $this->makeOrganization();
        $link = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $response = $this->put(route('links.update', $link), [
            'type' => 'website',
            'url' => 'https://updated.example.com',
            'title' => 'Updated',
        ]);

        $link->refresh();
        $this->assertSame('https://updated.example.com', $link->url);
        $this->assertSame('Updated', $link->title);

        $response->assertRedirect(route('organizations.show', $organization));
    }

    #[Test]
    public function update_cannot_reparent_a_link_via_form_tampering(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');

        $link = $orgA->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        // Even if the form sends a different linkable_type/id, the
        // update should ignore them — UpdateLinkRequest::rules()
        // deliberately omits these fields, so validated() strips them.
        $this->put(route('links.update', $link), [
            'linkable_type' => 'organization',
            'linkable_id' => $orgB->id,
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $link->refresh();
        $this->assertSame(Organization::class, $link->linkable_type);
        $this->assertSame($orgA->id, $link->linkable_id);
    }

    #[Test]
    public function update_to_internal_doc_can_clear_the_url(): void
    {
        $organization = $this->makeOrganization();
        $link = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $this->put(route('links.update', $link), [
            'type' => 'internal_doc',
            'url' => '',
            'title' => 'Now internal',
        ]);

        $link->refresh();
        $this->assertSame('internal_doc', $link->type);
        $this->assertNull($link->url);
        $this->assertSame('Now internal', $link->title);
    }

    // ────────────────────────────────────────────────────────────
    // Destroy
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function delete_soft_deletes_the_link_and_redirects_to_parent(): void
    {
        $organization = $this->makeOrganization();
        $link = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $response = $this->delete(route('links.destroy', $link));

        $this->assertSoftDeleted($link);
        $response->assertRedirect(route('organizations.show', $organization));
    }

    #[Test]
    public function visiting_a_soft_deleted_link_edit_page_returns_404(): void
    {
        $organization = $this->makeOrganization();
        $link = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);
        $link->delete();

        $this->get(route('links.edit', $link))->assertNotFound();
    }

    // ────────────────────────────────────────────────────────────
    // Show-page integration
    //
    // Verifies the links section renders correctly on each parent
    // type's show page. The section partial is included from four
    // separate show templates; if any wiring breaks, these catch it.
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function organization_show_page_renders_empty_links_section(): void
    {
        $organization = $this->makeOrganization();

        $this->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Links')
            ->assertSee('Add link');
    }

    #[Test]
    public function project_show_page_renders_empty_links_section(): void
    {
        $project = $this->makeProject();

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Links')
            ->assertSee('Add link');
    }

    #[Test]
    public function position_show_page_renders_empty_links_section(): void
    {
        $position = $this->makePosition();

        $this->get(route('positions.show', $position))
            ->assertOk()
            ->assertSee('Links')
            ->assertSee('Add link');
    }

    #[Test]
    public function accomplishment_show_page_renders_empty_links_section(): void
    {
        $accomplishment = $this->makeAccomplishment();

        $this->get(route('accomplishments.show', $accomplishment))
            ->assertOk()
            ->assertSee('Links')
            ->assertSee('Add link');
    }

    #[Test]
    public function show_page_renders_existing_links(): void
    {
        $organization = $this->makeOrganization();
        $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
            'title' => 'My test website',
        ]);

        $this->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('My test website');
    }

    #[Test]
    public function show_page_groups_personal_appearances_separately_when_both_kinds_exist(): void
    {
        $organization = $this->makeOrganization();
        $organization->links()->create([
            'type' => 'media_appearance',
            'url' => 'https://example.com/appearance',
            'title' => 'My interview',
            'is_personal_appearance' => true,
        ]);
        $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
            'title' => 'Main site',
        ]);

        $this->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Personal appearances')
            ->assertSee('Supporting links')
            ->assertSee('My interview')
            ->assertSee('Main site');
    }

    #[Test]
    public function show_page_omits_subheadings_when_only_one_group_exists(): void
    {
        // When all links are the same kind (e.g., all supporting), the
        // "Personal appearances" / "Supporting links" subheadings add
        // noise — the section partial only renders them when both
        // groups have at least one entry.
        $organization = $this->makeOrganization();
        $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
            'title' => 'Only link',
        ]);

        $this->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Only link')
            ->assertDontSee('Personal appearances')
            ->assertDontSee('Supporting links');
    }

    #[Test]
    public function deleted_links_do_not_appear_on_parent_show_page(): void
    {
        $organization = $this->makeOrganization();
        $link = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
            'title' => 'A deleted link',
        ]);

        $link->delete();

        $this->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertDontSee('A deleted link');
    }
}