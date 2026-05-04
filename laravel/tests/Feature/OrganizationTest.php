<?php

namespace Tests\Feature;

use App\Models\FundingRound;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Position;
use App\Models\Project;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_organization_can_be_created(): void
    {
        $organization = Organization::create([
            'name' => 'Test Company',
            'type' => 'employer',
            'website' => 'https://example.com',
            'founded_year' => 2020,
        ]);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Test Company',
            'type' => 'employer',
        ]);

        $this->assertNotNull($organization->id);
        $this->assertSame(2020, $organization->founded_year);
    }

    #[Test]
    public function an_organization_can_have_positions(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        Position::create([
            'organization_id' => $organization->id,
            'title' => 'Senior Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'location_arrangement' => 'remote',
        ]);

        $this->assertCount(1, $organization->positions);
        $this->assertSame('Senior Engineer', $organization->positions->first()->title);
    }

    #[Test]
    public function an_organization_can_have_projects(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        Project::create([
            'organization_id' => $organization->id,
            'name' => 'Internal Tools',
            'visibility' => 'internal',
            'contribution_level' => 'lead',
        ]);

        $this->assertCount(1, $organization->projects);
    }

    #[Test]
    public function an_organization_can_have_associated_people(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        Person::create([
            'name' => 'Alex Manager',
            'current_organization_id' => $organization->id,
        ]);

        $this->assertCount(1, $organization->people);
        $this->assertSame('Alex Manager', $organization->people->first()->name);
    }

    #[Test]
    public function an_organization_can_have_links(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $this->assertCount(1, $organization->links);
        $this->assertSame('website', $organization->links->first()->type);
    }

    #[Test]
    public function organizations_can_be_tagged(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        $tag = Tag::create([
            'name' => 'Bitcoin',
            'category' => 'domain',
        ]);

        $organization->tags()->attach($tag);

        $this->assertCount(1, $organization->tags);
        $this->assertSame('Bitcoin', $organization->tags->first()->name);

        // Inverse relationship works — tag knows its organizations
        $this->assertCount(1, $tag->organizations);
        $this->assertSame($organization->id, $tag->organizations->first()->id);
    }

    #[Test]
    public function soft_deleting_an_organization_preserves_funding_rounds(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        FundingRound::create([
            'organization_id' => $organization->id,
            'round_name' => 'Series A',
            'amount_raised' => 1_000_000_000,
        ]);

        $organization->delete();

        $this->assertSoftDeleted($organization);
        $this->assertDatabaseCount('funding_rounds', 1);
    }

    #[Test]
    public function force_deleting_an_organization_cascades_to_funding_rounds(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        FundingRound::create([
            'organization_id' => $organization->id,
            'round_name' => 'Series A',
            'amount_raised' => 1_000_000_000,
        ]);

        $organization->forceDelete();

        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
        $this->assertDatabaseCount('funding_rounds', 0);
    }

    #[Test]
    public function deleting_a_person_with_organization_sets_null_on_the_organization_side(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        $person = Person::create([
            'name' => 'Alex Manager',
            'current_organization_id' => $organization->id,
        ]);

        $organization->forceDelete();

        $person->refresh();
        $this->assertNull($person->current_organization_id);
    }
}