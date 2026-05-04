<?php

namespace Tests\Feature;

use App\Models\Accomplishment;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\Tag;
use App\Models\TagAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tag_aliases_can_be_attached_to_a_tag(): void
    {
        $tag = Tag::create([
            'name' => 'PostgreSQL',
            'category' => 'database',
        ]);

        $tag->aliases()->create(['alias' => 'Postgres']);
        $tag->aliases()->create(['alias' => 'postgres']);

        $this->assertCount(2, $tag->aliases);
    }

    #[Test]
    public function deleting_a_tag_cascades_to_its_aliases(): void
    {
        $tag = Tag::create([
            'name' => 'PostgreSQL',
            'category' => 'database',
        ]);

        $tag->aliases()->create(['alias' => 'Postgres']);
        $tag->aliases()->create(['alias' => 'postgres']);

        $this->assertDatabaseCount('tag_aliases', 2);

        $tag->delete();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseCount('tag_aliases', 0);
    }

    #[Test]
    public function an_alias_cannot_collide_with_an_existing_tag_name(): void
    {
        Tag::create([
            'name' => 'PostgreSQL',
            'category' => 'database',
        ]);

        $otherTag = Tag::create([
            'name' => 'MySQL',
            'category' => 'database',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $otherTag->aliases()->create(['alias' => 'PostgreSQL']);
    }

    #[Test]
    public function a_tag_name_cannot_collide_with_an_existing_alias(): void
    {
        $tag = Tag::create([
            'name' => 'PostgreSQL',
            'category' => 'database',
        ]);

        $tag->aliases()->create(['alias' => 'Postgres']);

        $this->expectException(InvalidArgumentException::class);

        Tag::create([
            'name' => 'Postgres',
            'category' => 'database',
        ]);
    }

    #[Test]
    public function tags_can_be_attached_to_multiple_entity_types(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'location_arrangement' => 'remote',
        ]);

        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Important Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Did a thing',
            'date' => '2023-03-15',
        ]);

        $sourceDocument = SourceDocument::create([
            'kind' => 'brag_doc',
            'body' => 'Some notes',
        ]);

        $tag = Tag::create([
            'name' => 'TypeScript',
            'category' => 'language',
        ]);

        $organization->tags()->attach($tag);
        $position->tags()->attach($tag);
        $project->tags()->attach($tag);
        $accomplishment->tags()->attach($tag);
        $sourceDocument->tags()->attach($tag);

        // Tag knows about all five entity types pointing back at it
        $this->assertCount(1, $tag->organizations);
        $this->assertCount(1, $tag->positions);
        $this->assertCount(1, $tag->projects);
        $this->assertCount(1, $tag->accomplishments);
        $this->assertCount(1, $tag->sourceDocuments);
    }
}