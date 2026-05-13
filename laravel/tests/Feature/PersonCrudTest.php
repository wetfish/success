<?php

namespace Tests\Feature;

use App\Models\Accomplishment;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HTTP-level tests for the people subsystem: Person CRUD, the
 * autocomplete search endpoint, and the picker's integration with
 * the three parent forms (position, project, accomplishment).
 *
 * Model-level relationship tests live in PersonTest. The duplication
 * boundary mirrors TagTest vs TagCrudTest: PersonTest verifies the
 * model in isolation, this suite verifies the routes, views,
 * validation, and integrations through the request pipeline.
 */
class PersonCrudTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────
    // Factories
    // ────────────────────────────────────────────────────────────

    private function makePerson(string $name = 'Sarah Chen', ?Organization $organization = null, array $overrides = []): Person
    {
        return Person::create(array_merge([
            'name' => $name,
            'current_organization_id' => $organization?->id,
        ], $overrides));
    }

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
            'title' => 'Shipped a thing',
            'description' => 'A thing was shipped',
            'date' => '2023-01-01',
            'confidence' => 3,
            'prominence' => 3,
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // Person index page
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_index_page_loads_with_empty_state_when_no_people_exist(): void
    {
        $this->get(route('people.index'))
            ->assertOk()
            ->assertSee('No people yet')
            ->assertSee('Add your first person');
    }

    #[Test]
    public function the_index_page_groups_people_by_their_current_organization(): void
    {
        $orgA = $this->makeOrganization('Alpha Corp');
        $orgB = $this->makeOrganization('Beta Inc');
        $this->makePerson('Alex Manager', $orgA);
        $this->makePerson('Sarah Chen', $orgB);

        $response = $this->get(route('people.index'));
        $response->assertOk();
        $response->assertSee('Alpha Corp');
        $response->assertSee('Beta Inc');
        $response->assertSee('Alex Manager');
        $response->assertSee('Sarah Chen');
    }

    #[Test]
    public function people_without_a_current_organization_appear_in_an_unaffiliated_bucket(): void
    {
        $this->makePerson('Standalone Person');

        $this->get(route('people.index'))
            ->assertOk()
            ->assertSee('Unaffiliated')
            ->assertSee('Standalone Person');
    }

    #[Test]
    public function the_index_page_renders_with_a_mix_of_affiliated_and_unaffiliated_people(): void
    {
        // This regression-tests the bug where Eloquent\Collection::except()
        // crashed on grouped Collection values. See the inline comment in
        // people/index.blade.php — `except` was replaced with `filter`.
        $org = $this->makeOrganization('Mixed Corp');
        $this->makePerson('Affiliated Person', $org);
        $this->makePerson('Floating Person');

        $this->get(route('people.index'))
            ->assertOk()
            ->assertSee('Mixed Corp')
            ->assertSee('Affiliated Person')
            ->assertSee('Floating Person')
            ->assertSee('Unaffiliated');
    }

    // ────────────────────────────────────────────────────────────
    // Person create / store
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_create_page_loads(): void
    {
        $this->get(route('people.create'))
            ->assertOk()
            ->assertSee('Add person');
    }

    #[Test]
    public function a_valid_person_can_be_created_with_full_data(): void
    {
        $org = $this->makeOrganization('Lightning Labs');

        $response = $this->post(route('people.store'), [
            'name' => 'Alex Manager',
            'current_title' => 'Engineering Manager',
            'current_organization_id' => $org->id,
            'email' => 'alex@example.com',
            'relationship_type' => 'manager',
            'user_notes' => 'Met at a conference',
        ]);

        $this->assertSame(1, Person::count());
        $person = Person::first();
        $this->assertSame('Alex Manager', $person->name);
        $this->assertSame('Engineering Manager', $person->current_title);
        $this->assertSame($org->id, $person->current_organization_id);
        $this->assertSame('manager', $person->relationship_type);

        $response->assertRedirect(route('people.show', $person));
    }

    #[Test]
    public function a_person_can_be_created_with_only_a_name(): void
    {
        // The quick-add scenario: the picker captures a minimum-viable
        // person record while in the middle of another form. Every
        // field except `name` is nullable.
        $this->post(route('people.store'), ['name' => 'Quick Add Person']);

        $this->assertSame(1, Person::count());
        $person = Person::first();
        $this->assertSame('Quick Add Person', $person->name);
        $this->assertNull($person->current_organization_id);
        $this->assertNull($person->relationship_type);
        $this->assertNull($person->email);
    }

    #[Test]
    public function the_name_field_is_required(): void
    {
        $response = $this->post(route('people.store'), ['name' => '']);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Person::count());
    }

    #[Test]
    public function email_is_normalized_to_lowercase(): void
    {
        // Casing matters for display but not for identity. The
        // PersonRules normalizer lowercases at submission so duplicate
        // detection and search operate on a normalized value.
        $this->post(route('people.store'), [
            'name' => 'Mixed Case Email',
            'email' => 'MiXeD@Example.COM',
        ]);

        $person = Person::first();
        $this->assertSame('mixed@example.com', $person->email);
    }

    #[Test]
    public function an_invalid_relationship_type_is_rejected(): void
    {
        $response = $this->post(route('people.store'), [
            'name' => 'Bad Relationship',
            'relationship_type' => 'not_a_real_type',
        ]);

        $response->assertSessionHasErrors('relationship_type');
        $this->assertSame(0, Person::count());
    }

    #[Test]
    public function a_nonexistent_current_organization_id_is_rejected(): void
    {
        // Stale IDs (e.g., the org was deleted between page load and
        // form submission) are caught by the `exists` rule, not
        // silently dropped.
        $response = $this->post(route('people.store'), [
            'name' => 'Org Stale Test',
            'current_organization_id' => 99999,
        ]);

        $response->assertSessionHasErrors('current_organization_id');
        $this->assertSame(0, Person::count());
    }

    // ────────────────────────────────────────────────────────────
    // Person edit / update
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_edit_page_loads_with_current_values(): void
    {
        $person = $this->makePerson('Existing Person', null, ['current_title' => 'Designer']);

        $this->get(route('people.edit', $person))
            ->assertOk()
            ->assertSee('Existing Person')
            ->assertSee('Designer');
    }

    #[Test]
    public function a_person_can_be_updated_without_changing_anything(): void
    {
        $person = $this->makePerson('Stable Person');

        $response = $this->put(route('people.update', $person), [
            'name' => 'Stable Person',
        ]);

        $response->assertRedirect(route('people.show', $person));
        $this->assertSame('Stable Person', $person->refresh()->name);
    }

    #[Test]
    public function a_persons_organization_can_be_updated(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $person = $this->makePerson('Mover', $orgA);

        $this->put(route('people.update', $person), [
            'name' => $person->name,
            'current_organization_id' => $orgB->id,
        ]);

        $this->assertSame($orgB->id, $person->refresh()->current_organization_id);
    }

    // ────────────────────────────────────────────────────────────
    // Person show
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_show_page_loads_with_basic_info(): void
    {
        $org = $this->makeOrganization('Show Co');
        $person = $this->makePerson('Show Person', $org, ['current_title' => 'CEO']);

        $this->get(route('people.show', $person))
            ->assertOk()
            ->assertSee('Show Person')
            ->assertSee('CEO')
            ->assertSee('Show Co');
    }

    #[Test]
    public function the_show_page_surfaces_position_collaborations_with_roles(): void
    {
        $person = $this->makePerson('Manager Alex');
        $org = $this->makeOrganization('Workplace');
        $position = $this->makePosition($org);
        $position->collaborators()->attach($person, ['role_on_position' => 'Manager']);

        $this->get(route('people.show', $person))
            ->assertOk()
            ->assertSee($position->title)
            ->assertSee('Manager');
    }

    #[Test]
    public function the_show_page_surfaces_project_collaborations_with_roles(): void
    {
        $person = $this->makePerson('Project Peer');
        $project = $this->makeProject();
        $project->collaborators()->attach($person, ['role_on_project' => 'Tech Lead']);

        $this->get(route('people.show', $person))
            ->assertOk()
            ->assertSee($project->name)
            ->assertSee('Tech Lead');
    }

    #[Test]
    public function the_show_page_surfaces_accomplishment_collaborations_with_roles(): void
    {
        $person = $this->makePerson('Accomp Helper');
        $accomplishment = $this->makeAccomplishment();
        $accomplishment->collaborators()->attach($person, ['role_on_accomplishment' => 'Co-author']);

        $this->get(route('people.show', $person))
            ->assertOk()
            ->assertSee($accomplishment->title)
            ->assertSee('Co-author');
    }

    #[Test]
    public function the_show_page_renders_empty_collaboration_state_when_no_attachments(): void
    {
        $person = $this->makePerson('Solo Person');

        $this->get(route('people.show', $person))
            ->assertOk()
            ->assertSee('No collaborations recorded yet');
    }

    // ────────────────────────────────────────────────────────────
    // Person destroy
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function a_person_can_be_soft_deleted(): void
    {
        $person = $this->makePerson('Bye Person');

        $response = $this->delete(route('people.destroy', $person));

        $response->assertRedirect(route('people.index'));
        $this->assertSame(0, Person::count()); // SoftDeletes hides the row
        $this->assertSame(1, Person::withTrashed()->count()); // Still in DB
    }

    #[Test]
    public function soft_deleting_a_person_preserves_their_collaborator_pivots(): void
    {
        // Intentional design: soft-delete preserves history. The
        // pivot rows continue to reference the soft-deleted person,
        // and restoring the person brings back the full attachment
        // surface. Only force-delete (DB cascade) wipes the pivots.
        $person = $this->makePerson('Preserve Me');
        $position = $this->makePosition();
        $position->collaborators()->attach($person, ['role_on_position' => 'Mentor']);

        $this->delete(route('people.destroy', $person));

        $this->assertDatabaseCount('position_collaborators', 1);
    }

    // ────────────────────────────────────────────────────────────
    // Search endpoint — empty / boundary
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_search_endpoint_returns_empty_array_for_empty_query(): void
    {
        $this->makePerson('Someone');

        $this->getJson(route('people.search', ['q' => '']))
            ->assertOk()
            ->assertExactJson([]);
    }

    #[Test]
    public function the_search_endpoint_returns_empty_array_for_whitespace_query(): void
    {
        $this->makePerson('Someone');

        $this->getJson(route('people.search', ['q' => '   ']))
            ->assertOk()
            ->assertExactJson([]);
    }

    #[Test]
    public function the_search_endpoint_returns_empty_array_when_nothing_matches(): void
    {
        $this->makePerson('Alice');

        $this->getJson(route('people.search', ['q' => 'zzz']))
            ->assertOk()
            ->assertExactJson([]);
    }

    #[Test]
    public function the_search_endpoint_caps_results_at_five(): void
    {
        // 10 people all with names matching prefix "alex" — the
        // endpoint must cap the response at 5.
        for ($i = 1; $i <= 10; $i++) {
            $this->makePerson('Alex Person ' . $i);
        }

        $this->getJson(route('people.search', ['q' => 'alex']))
            ->assertOk()
            ->assertJsonCount(5);
    }

    // ────────────────────────────────────────────────────────────
    // Search endpoint — ranking
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function name_prefix_matches_outrank_substring_matches(): void
    {
        // "alex" matches:
        //   - "Alex Manager" via tier 1 (name prefix)
        //   - "Sarah Alex" via tier 2 (name substring)
        // Tier 1 < tier 2, so Alex Manager comes first.
        $this->makePerson('Sarah Alex');
        $this->makePerson('Alex Manager');

        $data = $this->getJson(route('people.search', ['q' => 'alex']))
            ->json();

        $this->assertCount(2, $data);
        $this->assertSame('Alex Manager', $data[0]['name']);
        $this->assertSame('Sarah Alex', $data[1]['name']);
    }

    #[Test]
    public function within_a_tier_results_are_alphabetical(): void
    {
        // Both "Cat" and "Charlie" prefix-match "c" — alphabetically
        // Cat comes before Charlie.
        $this->makePerson('Charlie');
        $this->makePerson('Cat');

        $data = $this->getJson(route('people.search', ['q' => 'c']))
            ->json();

        $this->assertCount(2, $data);
        $this->assertSame('Cat', $data[0]['name']);
        $this->assertSame('Charlie', $data[1]['name']);
    }

    #[Test]
    public function search_is_case_insensitive(): void
    {
        $this->makePerson('Sarah Chen');

        $upper = $this->getJson(route('people.search', ['q' => 'SARAH']));
        $lower = $this->getJson(route('people.search', ['q' => 'sarah']));

        $upper->assertJsonFragment(['name' => 'Sarah Chen']);
        $lower->assertJsonFragment(['name' => 'Sarah Chen']);
    }

    #[Test]
    public function search_excludes_soft_deleted_people(): void
    {
        $person = $this->makePerson('Deleted Person');
        $person->delete();

        $this->getJson(route('people.search', ['q' => 'deleted']))
            ->assertOk()
            ->assertExactJson([]);
    }

    #[Test]
    public function search_response_includes_current_title_and_organization(): void
    {
        $org = $this->makeOrganization('GiveButter');
        $this->makePerson('Max Friedman', $org, ['current_title' => 'CEO']);

        $this->getJson(route('people.search', ['q' => 'max']))
            ->assertJsonFragment([
                'name' => 'Max Friedman',
                'current_title' => 'CEO',
                'current_organization_name' => 'GiveButter',
            ]);
    }

    #[Test]
    public function search_response_includes_null_when_title_or_organization_missing(): void
    {
        $this->makePerson('No Context Person');

        $this->getJson(route('people.search', ['q' => 'no context']))
            ->assertJsonFragment([
                'name' => 'No Context Person',
                'current_title' => null,
                'current_organization_name' => null,
            ]);
    }

    // ────────────────────────────────────────────────────────────
    // Picker integration — collaborator sync on parent forms
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function collaborators_get_attached_when_creating_a_position(): void
    {
        $org = $this->makeOrganization();
        $alex = $this->makePerson('Alex');
        $sarah = $this->makePerson('Sarah');

        $this->post(route('positions.store'), [
            'organization_id' => $org->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
            'collaborators' => [
                ['person_id' => $alex->id, 'role' => 'Manager'],
                ['person_id' => $sarah->id, 'role' => 'Peer'],
            ],
        ]);

        $position = Position::first();
        $this->assertCount(2, $position->collaborators);
        $roles = $position->collaborators->pluck('pivot.role_on_position')->sort()->values()->all();
        $this->assertSame(['Manager', 'Peer'], $roles);
    }

    #[Test]
    public function collaborators_get_synced_when_updating_a_position(): void
    {
        // Sync semantics: submitting a new set replaces the old one.
        $position = $this->makePosition();
        $alex = $this->makePerson('Alex');
        $sarah = $this->makePerson('Sarah');
        $position->collaborators()->attach($alex, ['role_on_position' => 'Manager']);

        $this->put(route('positions.update', $position), [
            'organization_id' => $position->organization_id,
            'title' => $position->title,
            'employment_type' => $position->employment_type,
            'location_arrangement' => $position->location_arrangement,
            'start_date' => $position->start_date->format('Y-m-d'),
            // Submit only Sarah — Alex should be detached.
            'collaborators' => [
                ['person_id' => $sarah->id, 'role' => 'Peer'],
            ],
        ]);

        $position->refresh();
        $this->assertCount(1, $position->collaborators);
        $this->assertSame('Sarah', $position->collaborators->first()->name);
    }

    #[Test]
    public function omitting_collaborators_detaches_all(): void
    {
        // If the user removes all chips before save, the form submits
        // no collaborators key (or an empty array) and sync wipes
        // existing attachments. Matches "what you see is what's saved".
        $position = $this->makePosition();
        $alex = $this->makePerson('Alex');
        $position->collaborators()->attach($alex, ['role_on_position' => 'Manager']);

        $this->put(route('positions.update', $position), [
            'organization_id' => $position->organization_id,
            'title' => $position->title,
            'employment_type' => $position->employment_type,
            'location_arrangement' => $position->location_arrangement,
            'start_date' => $position->start_date->format('Y-m-d'),
            // No collaborators submitted.
        ]);

        $this->assertCount(0, $position->refresh()->collaborators);
    }

    #[Test]
    public function empty_role_normalizes_to_null_in_the_pivot(): void
    {
        // PersonRules::buildCollaboratorSyncData converts empty role
        // strings to null. The DB column is nullable so this stores
        // a consistent "no role specified" sentinel.
        $position = $this->makePosition();
        $alex = $this->makePerson('Alex');

        $this->put(route('positions.update', $position), [
            'organization_id' => $position->organization_id,
            'title' => $position->title,
            'employment_type' => $position->employment_type,
            'location_arrangement' => $position->location_arrangement,
            'start_date' => $position->start_date->format('Y-m-d'),
            'collaborators' => [
                ['person_id' => $alex->id, 'role' => ''],
            ],
        ]);

        $position->refresh();
        $this->assertNull($position->collaborators->first()->pivot->role_on_position);
    }

    #[Test]
    public function an_invalid_person_id_is_rejected(): void
    {
        $position = $this->makePosition();

        $response = $this->put(route('positions.update', $position), [
            'organization_id' => $position->organization_id,
            'title' => $position->title,
            'employment_type' => $position->employment_type,
            'location_arrangement' => $position->location_arrangement,
            'start_date' => $position->start_date->format('Y-m-d'),
            'collaborators' => [
                ['person_id' => 99999, 'role' => 'Manager'],
            ],
        ]);

        $response->assertSessionHasErrors('collaborators.0.person_id');
    }

    #[Test]
    public function collaborators_get_attached_when_creating_a_project(): void
    {
        $org = $this->makeOrganization();
        $alex = $this->makePerson('Alex');

        $this->post(route('projects.store'), [
            'organization_id' => $org->id,
            'name' => 'Tagged Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
            'collaborators' => [
                ['person_id' => $alex->id, 'role' => 'Tech Lead'],
            ],
        ]);

        $project = Project::first();
        $this->assertCount(1, $project->collaborators);
        $this->assertSame('Tech Lead', $project->collaborators->first()->pivot->role_on_project);
    }

    #[Test]
    public function collaborators_get_attached_when_creating_an_accomplishment(): void
    {
        $project = $this->makeProject();
        $alex = $this->makePerson('Alex');

        $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'title' => 'Tagged Accomplishment',
            'description' => 'Test',
            'date' => '2023-01-01',
            'confidence' => 3,
            'prominence' => 3,
            'collaborators' => [
                ['person_id' => $alex->id, 'role' => 'Co-author'],
            ],
        ]);

        $accomplishment = Accomplishment::first();
        $this->assertCount(1, $accomplishment->collaborators);
        $this->assertSame('Co-author', $accomplishment->collaborators->first()->pivot->role_on_accomplishment);
    }

    #[Test]
    public function sparse_collaborator_indices_are_handled(): void
    {
        // After the picker removes a chip, indices become sparse —
        // e.g., collaborators[0] and collaborators[2] exist but [1]
        // doesn't. Laravel collects sparse arrays correctly and the
        // helper iterates values without caring about keys.
        $position = $this->makePosition();
        $alex = $this->makePerson('Alex');
        $sarah = $this->makePerson('Sarah');

        $this->put(route('positions.update', $position), [
            'organization_id' => $position->organization_id,
            'title' => $position->title,
            'employment_type' => $position->employment_type,
            'location_arrangement' => $position->location_arrangement,
            'start_date' => $position->start_date->format('Y-m-d'),
            'collaborators' => [
                0 => ['person_id' => $alex->id, 'role' => 'Manager'],
                // Index 1 missing — picker removed chip 1.
                2 => ['person_id' => $sarah->id, 'role' => 'Peer'],
            ],
        ]);

        $this->assertCount(2, $position->refresh()->collaborators);
    }

    // ────────────────────────────────────────────────────────────
    // Picker form rendering
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_picker_renders_on_all_three_parent_edit_forms(): void
    {
        $position = $this->makePosition();
        $project = $this->makeProject();
        $accomplishment = $this->makeAccomplishment();

        foreach ([
            route('positions.edit', $position),
            route('projects.edit', $project),
            route('accomplishments.edit', $accomplishment),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('data-person-picker', escape: false);
        }
    }

    #[Test]
    public function server_rendered_chips_appear_on_position_edit_with_attached_collaborators(): void
    {
        $position = $this->makePosition();
        $alex = $this->makePerson('Alex Server');
        $position->collaborators()->attach($alex, ['role_on_position' => 'Manager']);

        $response = $this->get(route('positions.edit', $position));
        $response->assertOk();
        $response->assertSee('Alex Server');
        $response->assertSee('Manager');
        $response->assertSee('data-person-id="' . $alex->id . '"', escape: false);
    }
}