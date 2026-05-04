<?php

namespace Tests\Feature;

use App\Models\FundingRound;
use App\Models\Organization;
use App\Models\Tag;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schema integration test for the first slice of models.
 *
 * Exercises Tag, TagAlias, Organization, and FundingRound through their
 * relationships, soft-delete behavior, and the integer-cents money
 * convention. As more models are built in subsequent slices, this test
 * file will grow to cover those additions.
 */
class SchemaIntegrationTest extends TestCase
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
    public function an_organization_can_have_funding_rounds(): void
    {
        $organization = Organization::create([
            'name' => 'Test Startup',
            'type' => 'employer',
        ]);

        $round = FundingRound::create([
            'organization_id' => $organization->id,
            'round_name' => 'Series A',
            'round_date' => '2023-01-15',
            'amount_raised' => 5_000_000_000, // $50M in cents
            'currency' => 'USD',
        ]);

        $this->assertCount(1, $organization->fundingRounds);
        $this->assertSame($organization->id, $round->organization->id);
    }

    #[Test]
    public function funding_round_amounts_persist_as_integer_cents(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        $round = FundingRound::create([
            'organization_id' => $organization->id,
            'round_name' => 'Seed',
            'amount_raised' => 25_000_000, // $250k in cents
        ]);

        // Reload from database to verify storage
        $round->refresh();

        $this->assertSame(25_000_000, $round->amount_raised);
        $this->assertIsInt($round->amount_raised);
    }

    #[Test]
    public function money_helper_formats_amount_raised_for_display(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        $round = FundingRound::create([
            'organization_id' => $organization->id,
            'round_name' => 'Series B',
            'amount_raised' => 7_000_000_000, // $70M in cents
        ]);

        $this->assertSame('70000000.00', Money::format($round->amount_raised));
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

        // Verify the inverse relationship works — tag knows its organizations
        $this->assertCount(1, $tag->organizations);
        $this->assertSame($organization->id, $tag->organizations->first()->id);
    }

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

        $organization->delete(); // soft delete

        $this->assertSoftDeleted($organization);

        // Funding round still exists in the database — soft delete doesn't
        // cascade. Cascade only fires on a hard delete (forceDelete).
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
    public function deleting_a_tag_cascades_to_its_aliases(): void
    {
        $tag = Tag::create([
            'name' => 'PostgreSQL',
            'category' => 'database',
        ]);

        $tag->aliases()->create(['alias' => 'Postgres']);
        $tag->aliases()->create(['alias' => 'postgres']);

        $this->assertDatabaseCount('tag_aliases', 2);

        // Tags don't use soft deletes — this is a hard delete
        $tag->delete();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseCount('tag_aliases', 0);
    }
}