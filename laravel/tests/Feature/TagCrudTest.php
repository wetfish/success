<?php

namespace Tests\Feature;

use App\Models\Accomplishment;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use App\Models\Tag;
use App\Models\TagAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end tests for tag CRUD, tag-alias CRUD, the autocomplete
 * search endpoint, and the picker's integration with the four
 * parent forms.
 *
 * Model-level invariants (cross-table name/alias collision) also
 * have coverage in TagTest. The duplication is deliberate: that
 * suite verifies the model in isolation, this one verifies the
 * invariants surface as friendly validation errors through the
 * form layer.
 */
class TagCrudTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────
    // Factories
    // ────────────────────────────────────────────────────────────

    private function makeTag(string $name, ?string $category = null): Tag
    {
        return Tag::create([
            'name' => $name,
            'category' => $category,
        ]);
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
            'title' => 'Test Accomplishment',
            'description' => 'A thing I did',
            'date' => '2023-01-01',
            'confidence' => 3,
            'prominence' => 3,
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // Tag index page
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_index_page_loads_with_empty_state_when_no_tags_exist(): void
    {
        $this->get(route('tags.index'))
            ->assertOk()
            ->assertSee('No tags yet')
            ->assertSee('Add your first tag');
    }

    #[Test]
    public function the_index_page_shows_tags_grouped_by_category(): void
    {
        $this->makeTag('Python', 'language');
        $this->makeTag('Pytest', 'framework');
        $this->makeTag('Postgres', 'tool');

        $response = $this->get(route('tags.index'));
        $response->assertOk();
        $response->assertSee('Programming language');
        $response->assertSee('Framework');
        $response->assertSee('Tool');
        $response->assertSee('Python');
        $response->assertSee('Pytest');
        $response->assertSee('Postgres');
    }

    #[Test]
    public function the_index_page_shows_uncategorized_tags_in_their_own_bucket(): void
    {
        $this->makeTag('Misc Tag'); // No category

        $this->get(route('tags.index'))
            ->assertOk()
            ->assertSee('Uncategorized')
            ->assertSee('Misc Tag');
    }

    #[Test]
    public function the_index_page_shows_usage_counts(): void
    {
        $tag = $this->makeTag('Python', 'language');
        $project = $this->makeProject();
        $project->tags()->attach($tag);

        $this->get(route('tags.index'))
            ->assertOk()
            ->assertSee('1 use');
    }

    #[Test]
    public function the_index_page_marks_unused_tags(): void
    {
        $this->makeTag('Unused Tag', 'tool');

        $this->get(route('tags.index'))
            ->assertOk()
            ->assertSee('Unused');
    }

    // ────────────────────────────────────────────────────────────
    // Tag create / store
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_create_page_loads(): void
    {
        $this->get(route('tags.create'))
            ->assertOk()
            ->assertSee('Add tag');
    }

    #[Test]
    public function a_valid_tag_can_be_created(): void
    {
        $response = $this->post(route('tags.store'), [
            'name' => 'Python',
            'category' => 'language',
            'description' => 'A snake-y programming language',
        ]);

        $this->assertSame(1, Tag::count());
        $tag = Tag::first();
        $this->assertSame('Python', $tag->name);
        $this->assertSame('language', $tag->category);

        $response->assertRedirect(route('tags.edit', $tag));
    }

    #[Test]
    public function a_tag_can_be_created_with_only_a_name(): void
    {
        $this->post(route('tags.store'), ['name' => 'Solo Tag']);

        $this->assertSame(1, Tag::count());
        $this->assertNull(Tag::first()->category);
    }

    #[Test]
    public function the_name_field_is_required(): void
    {
        $response = $this->post(route('tags.store'), ['name' => '']);
        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Tag::count());
    }

    #[Test]
    public function the_name_field_must_be_unique(): void
    {
        $this->makeTag('Python', 'language');

        $response = $this->post(route('tags.store'), [
            'name' => 'Python',
            'category' => 'language',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Tag::count());
    }

    #[Test]
    public function the_category_must_be_in_the_accepted_list(): void
    {
        $response = $this->post(route('tags.store'), [
            'name' => 'Python',
            'category' => 'not_a_real_category',
        ]);

        $response->assertSessionHasErrors('category');
    }

    #[Test]
    public function a_tag_name_cannot_collide_with_an_existing_alias(): void
    {
        $existing = $this->makeTag('PostgreSQL', 'tool');
        $existing->aliases()->create(['alias' => 'Postgres']);

        $response = $this->post(route('tags.store'), [
            'name' => 'Postgres',
            'category' => 'tool',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Tag::count());
    }

    // ────────────────────────────────────────────────────────────
    // Tag edit / update
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_edit_page_loads_with_current_values(): void
    {
        $tag = $this->makeTag('Python', 'language');

        $this->get(route('tags.edit', $tag))
            ->assertOk()
            ->assertSee('Python')
            ->assertSee('value="Python"', escape: false);
    }

    #[Test]
    public function the_edit_page_shows_existing_aliases(): void
    {
        $tag = $this->makeTag('Python', 'language');
        $tag->aliases()->create(['alias' => 'py']);

        $this->get(route('tags.edit', $tag))
            ->assertOk()
            ->assertSee('py');
    }

    #[Test]
    public function a_tag_can_be_updated_without_changing_the_name(): void
    {
        // The uniqueness check must skip the current record on update.
        $tag = $this->makeTag('Python', 'language');

        $this->put(route('tags.update', $tag), [
            'name' => 'Python',
            'category' => 'framework',
            'description' => 'Updated description',
        ]);

        $tag->refresh();
        $this->assertSame('framework', $tag->category);
        $this->assertSame('Updated description', $tag->description);
    }

    #[Test]
    public function a_tag_name_can_be_changed_if_no_collision(): void
    {
        $tag = $this->makeTag('python', 'language');

        $this->put(route('tags.update', $tag), [
            'name' => 'Python',
            'category' => 'language',
        ]);

        $this->assertSame('Python', $tag->refresh()->name);
    }

    #[Test]
    public function updating_a_tag_to_an_existing_name_fails(): void
    {
        $this->makeTag('Python', 'language');
        $other = $this->makeTag('Pytest', 'framework');

        $response = $this->put(route('tags.update', $other), [
            'name' => 'Python',
            'category' => 'framework',
        ]);

        $response->assertSessionHasErrors('name');
    }

    // ────────────────────────────────────────────────────────────
    // Tag destroy
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function a_tag_can_be_hard_deleted(): void
    {
        $tag = $this->makeTag('Python', 'language');

        $response = $this->delete(route('tags.destroy', $tag));

        $this->assertSame(0, Tag::count());
        $response->assertRedirect(route('tags.index'));
    }

    #[Test]
    public function deleting_a_tag_cascades_to_aliases_and_taggables(): void
    {
        $tag = $this->makeTag('Python', 'language');
        $tag->aliases()->create(['alias' => 'py']);
        $project = $this->makeProject();
        $project->tags()->attach($tag);

        $this->assertDatabaseCount('tag_aliases', 1);
        $this->assertDatabaseCount('taggables', 1);

        $this->delete(route('tags.destroy', $tag));

        $this->assertDatabaseCount('tag_aliases', 0);
        $this->assertDatabaseCount('taggables', 0);
    }

    // ────────────────────────────────────────────────────────────
    // Tag alias create / destroy
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function an_alias_can_be_added_to_a_tag(): void
    {
        $tag = $this->makeTag('PostgreSQL', 'tool');

        $response = $this->post(route('tag-aliases.store', $tag), [
            'alias' => 'Postgres',
        ]);

        $this->assertSame(1, TagAlias::count());
        $alias = TagAlias::first();
        $this->assertSame('Postgres', $alias->alias);
        $this->assertSame($tag->id, $alias->tag_id);

        $response->assertRedirect(route('tags.edit', $tag) . '#aliases');
    }

    #[Test]
    public function an_alias_cannot_collide_with_an_existing_tag_name(): void
    {
        $this->makeTag('Postgres', 'tool');
        $other = $this->makeTag('PostgreSQL', 'tool');

        $response = $this->post(route('tag-aliases.store', $other), [
            'alias' => 'Postgres',
        ]);

        $response->assertSessionHasErrors('alias');
        $this->assertSame(0, TagAlias::count());
    }

    #[Test]
    public function alias_text_must_be_unique_across_all_aliases(): void
    {
        $tagA = $this->makeTag('PostgreSQL', 'tool');
        $tagB = $this->makeTag('Postgres CLI Tools', 'tool');
        $tagA->aliases()->create(['alias' => 'pg']);

        $response = $this->post(route('tag-aliases.store', $tagB), [
            'alias' => 'pg',
        ]);

        $response->assertSessionHasErrors('alias');
        $this->assertSame(1, TagAlias::count());
    }

    #[Test]
    public function an_empty_alias_is_rejected(): void
    {
        $tag = $this->makeTag('PostgreSQL', 'tool');

        $response = $this->post(route('tag-aliases.store', $tag), [
            'alias' => '',
        ]);

        $response->assertSessionHasErrors('alias');
    }

    #[Test]
    public function an_alias_can_be_deleted(): void
    {
        $tag = $this->makeTag('PostgreSQL', 'tool');
        $alias = $tag->aliases()->create(['alias' => 'Postgres']);

        $response = $this->delete(route('tag-aliases.destroy', [$tag, $alias]));

        $this->assertSame(0, TagAlias::count());
        $response->assertRedirect(route('tags.edit', $tag) . '#aliases');
    }

    #[Test]
    public function deleting_an_alias_requires_matching_parent_tag_in_url(): void
    {
        // URL-tampering guard: the alias must belong to the tag named
        // in the URL. Crafting `tags/{otherTag}/aliases/{aliasOfTagA}`
        // must 404 even though both records exist.
        $tagA = $this->makeTag('PostgreSQL', 'tool');
        $tagB = $this->makeTag('Python', 'language');
        $alias = $tagA->aliases()->create(['alias' => 'Postgres']);

        $response = $this->delete(route('tag-aliases.destroy', [$tagB, $alias]));

        $response->assertNotFound();
        $this->assertSame(1, TagAlias::count());
    }

    // ────────────────────────────────────────────────────────────
    // Search endpoint — empty / boundary
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_search_endpoint_returns_empty_array_for_empty_query(): void
    {
        $this->makeTag('Python', 'language');

        $this->getJson(route('tags.search', ['q' => '']))
            ->assertOk()
            ->assertExactJson([]);
    }

    #[Test]
    public function the_search_endpoint_returns_empty_array_for_whitespace_query(): void
    {
        $this->makeTag('Python', 'language');

        $this->getJson(route('tags.search', ['q' => '   ']))
            ->assertOk()
            ->assertExactJson([]);
    }

    #[Test]
    public function the_search_endpoint_returns_empty_array_when_nothing_matches(): void
    {
        $this->makeTag('Python', 'language');

        $this->getJson(route('tags.search', ['q' => 'zzz']))
            ->assertOk()
            ->assertExactJson([]);
    }

    #[Test]
    public function the_search_endpoint_caps_results_at_five(): void
    {
        // Create 10 tags that all start with "Py" so they all match
        // a tier-1 query for "py". The endpoint must return at most 5.
        for ($i = 1; $i <= 10; $i++) {
            $this->makeTag('PyTag' . $i, 'language');
        }

        $response = $this->getJson(route('tags.search', ['q' => 'py']));
        $response->assertOk();
        $response->assertJsonCount(5);
    }

    // ────────────────────────────────────────────────────────────
    // Search endpoint — match tiers
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function tier_one_name_prefix_matches(): void
    {
        $this->makeTag('Python', 'language');

        $response = $this->getJson(route('tags.search', ['q' => 'pyth']));
        $response->assertJsonFragment([
            'name' => 'Python',
            'matched_alias' => null,
        ]);
    }

    #[Test]
    public function tier_two_alias_prefix_matches(): void
    {
        $tag = $this->makeTag('PostgreSQL', 'tool');
        $tag->aliases()->create(['alias' => 'postgres']);

        $response = $this->getJson(route('tags.search', ['q' => 'postgr']));
        $response->assertJsonFragment([
            'name' => 'PostgreSQL',
            'matched_alias' => 'postgres',
        ]);
    }

    #[Test]
    public function tier_three_name_substring_matches(): void
    {
        $this->makeTag('Hyperscript', 'language');

        $response = $this->getJson(route('tags.search', ['q' => 'script']));
        $response->assertJsonFragment([
            'name' => 'Hyperscript',
            'matched_alias' => null,
        ]);
    }

    #[Test]
    public function tier_four_alias_substring_matches(): void
    {
        $tag = $this->makeTag('PostgreSQL', 'tool');
        $tag->aliases()->create(['alias' => 'pg-database']);

        $response = $this->getJson(route('tags.search', ['q' => 'database']));
        $response->assertJsonFragment([
            'name' => 'PostgreSQL',
            'matched_alias' => 'pg-database',
        ]);
    }

    #[Test]
    public function lower_tier_matches_outrank_higher_tier_matches(): void
    {
        // "py" matches:
        //   - "Pytest" via tier 1 (name prefix)
        //   - "Python" via tier 1 (name prefix)
        //   - "Hyperpy" via tier 3 (name substring)
        // Alphabetically within tier 1, Pytest precedes Python.
        // Tier 3 "Hyperpy" comes after both tier 1 matches.
        $this->makeTag('Hyperpy', 'language');
        $this->makeTag('Python', 'language');
        $this->makeTag('Pytest', 'framework');

        $response = $this->getJson(route('tags.search', ['q' => 'py']));
        $data = $response->json();

        $this->assertCount(3, $data);
        $this->assertSame('Pytest', $data[0]['name']);
        $this->assertSame('Python', $data[1]['name']);
        $this->assertSame('Hyperpy', $data[2]['name']);
    }

    #[Test]
    public function name_match_beats_alias_match_at_same_tier(): void
    {
        // "test" matches:
        //   - "Pytest" via tier 3 (name substring)
        //   - "JUnit" via tier 4 (alias substring "test-tool")
        // Tier 3 < tier 4, so Pytest comes first.
        $this->makeTag('Pytest', 'framework');
        $junit = $this->makeTag('JUnit', 'framework');
        $junit->aliases()->create(['alias' => 'test-tool']);

        $response = $this->getJson(route('tags.search', ['q' => 'test']));
        $data = $response->json();

        $this->assertCount(2, $data);
        $this->assertSame('Pytest', $data[0]['name']);
        $this->assertSame('JUnit', $data[1]['name']);
    }

    #[Test]
    public function search_results_include_category_label(): void
    {
        $this->makeTag('Python', 'language');

        $response = $this->getJson(route('tags.search', ['q' => 'pyth']));
        $response->assertJsonFragment([
            'name' => 'Python',
            'category' => 'language',
            'category_label' => 'Programming language',
        ]);
    }

    #[Test]
    public function search_results_for_uncategorized_tags_have_null_category(): void
    {
        $this->makeTag('Misc Tag');

        $response = $this->getJson(route('tags.search', ['q' => 'misc']));
        $response->assertJsonFragment([
            'name' => 'Misc Tag',
            'category' => null,
            'category_label' => null,
        ]);
    }

    #[Test]
    public function search_is_case_insensitive(): void
    {
        $this->makeTag('Python', 'language');

        $this->getJson(route('tags.search', ['q' => 'PYTH']))
            ->assertJsonFragment(['name' => 'Python']);

        $this->getJson(route('tags.search', ['q' => 'pyth']))
            ->assertJsonFragment(['name' => 'Python']);
    }

    #[Test]
    public function a_tag_only_appears_once_when_multiple_sources_match(): void
    {
        // "post" matches both the canonical name "PostgreSQL" (tier 1)
        // AND the alias "postgres" (tier 2). The result should contain
        // exactly one row for the tag, with the best (lowest) tier
        // determining the matched_alias output. Since tier 1 wins and
        // tier 1 comes from the name, matched_alias is null.
        $tag = $this->makeTag('PostgreSQL', 'tool');
        $tag->aliases()->create(['alias' => 'postgres']);

        $response = $this->getJson(route('tags.search', ['q' => 'post']));
        $data = $response->json();

        $this->assertCount(1, $data);
        $this->assertSame('PostgreSQL', $data[0]['name']);
        $this->assertNull($data[0]['matched_alias']);
    }

    // ────────────────────────────────────────────────────────────
    // Picker integration: tags get synced to parent entities on save
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function tags_get_attached_when_creating_an_organization(): void
    {
        $python = $this->makeTag('Python', 'language');
        $rust = $this->makeTag('Rust', 'language');

        $this->post(route('organizations.store'), [
            'name' => 'Tagged Org',
            'type' => 'employer',
            'tag_ids' => [$python->id, $rust->id],
        ]);

        $org = Organization::where('name', 'Tagged Org')->first();
        $this->assertNotNull($org);
        $this->assertCount(2, $org->tags);
    }

    #[Test]
    public function tags_get_synced_when_updating_an_organization(): void
    {
        $org = $this->makeOrganization();
        $python = $this->makeTag('Python', 'language');
        $rust = $this->makeTag('Rust', 'language');

        $org->tags()->attach($python);
        $this->assertCount(1, $org->tags()->get());

        $this->put(route('organizations.update', $org), [
            'name' => $org->name,
            'type' => $org->type,
            'tag_ids' => [$rust->id], // Replace python with rust
        ]);

        $org->refresh();
        $tags = $org->tags;
        $this->assertCount(1, $tags);
        $this->assertSame('Rust', $tags->first()->name);
    }

    #[Test]
    public function omitting_tag_ids_detaches_all_tags(): void
    {
        // Sync semantics: if the form sends no tag_ids, all existing
        // attachments are removed. Matches the form's "what you see is
        // what's saved" contract.
        $org = $this->makeOrganization();
        $python = $this->makeTag('Python', 'language');
        $org->tags()->attach($python);

        $this->put(route('organizations.update', $org), [
            'name' => $org->name,
            'type' => $org->type,
            // No tag_ids submitted
        ]);

        $this->assertCount(0, $org->refresh()->tags);
    }

    #[Test]
    public function an_invalid_tag_id_fails_validation(): void
    {
        // Stale or fabricated IDs are caught by the `exists:tags,id`
        // rule, not silently dropped.
        $org = $this->makeOrganization();

        $response = $this->put(route('organizations.update', $org), [
            'name' => $org->name,
            'type' => $org->type,
            'tag_ids' => [99999],
        ]);

        $response->assertSessionHasErrors('tag_ids.0');
    }

    #[Test]
    public function tags_get_attached_when_creating_a_position(): void
    {
        $org = $this->makeOrganization();
        $python = $this->makeTag('Python', 'language');

        $this->post(route('positions.store'), [
            'organization_id' => $org->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'location_arrangement' => 'remote',
            'start_date' => '2022-01-01',
            'tag_ids' => [$python->id],
        ]);

        $position = Position::first();
        $this->assertCount(1, $position->tags);
    }

    #[Test]
    public function tags_get_attached_when_creating_a_project(): void
    {
        $org = $this->makeOrganization();
        $python = $this->makeTag('Python', 'language');

        $this->post(route('projects.store'), [
            'organization_id' => $org->id,
            'name' => 'Tagged Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
            'date_precision' => 'month',
            'tag_ids' => [$python->id],
        ]);

        $project = Project::first();
        $this->assertCount(1, $project->tags);
    }

    #[Test]
    public function tags_get_attached_when_creating_an_accomplishment(): void
    {
        $project = $this->makeProject();
        $python = $this->makeTag('Python', 'language');

        $this->post(route('accomplishments.store'), [
            'project_id' => $project->id,
            'title' => 'Tagged Accomplishment',
            'description' => 'Test',
            'date' => '2023-01-01',
            'confidence' => 3,
            'prominence' => 3,
            'tag_ids' => [$python->id],
        ]);

        $accomplishment = Accomplishment::first();
        $this->assertCount(1, $accomplishment->tags);
    }

    // ────────────────────────────────────────────────────────────
    // Picker form rendering
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function the_picker_renders_on_organization_edit_with_attached_tags_as_chips(): void
    {
        $org = $this->makeOrganization();
        $python = $this->makeTag('Python', 'language');
        $org->tags()->attach($python);

        $this->get(route('organizations.edit', $org))
            ->assertOk()
            ->assertSee('Python')
            ->assertSee('data-tag-id="' . $python->id . '"', escape: false)
            ->assertSee('name="tag_ids[]"', escape: false);
    }

    #[Test]
    public function the_picker_renders_on_organization_create_with_no_chips(): void
    {
        $tag = $this->makeTag('Python', 'language');

        $response = $this->get(route('organizations.create'));
        $response->assertOk();
        $response->assertSee('data-tag-picker', escape: false);
        $response->assertSee('Type to search tags', escape: false);
        // The tag exists but isn't attached to any org yet, so the
        // create form must not render a chip for it.
        $response->assertDontSee('data-tag-id="' . $tag->id . '"', escape: false);
    }

    #[Test]
    public function the_picker_renders_on_all_four_parent_forms(): void
    {
        // Quick smoke test: each parent form's edit page mounts the
        // picker. If a form forgets the @include, this catches it.
        $org = $this->makeOrganization();
        $position = $this->makePosition($org);
        $project = $this->makeProject($org);
        $accomplishment = $this->makeAccomplishment($project);

        foreach ([
            route('organizations.edit', $org),
            route('positions.edit', $position),
            route('projects.edit', $project),
            route('accomplishments.edit', $accomplishment),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('data-tag-picker', escape: false);
        }
    }
}