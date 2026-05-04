<?php

namespace Tests\Feature;

use App\Models\FundingRound;
use App\Models\Organization;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FundingRoundTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Test Startup',
            'type' => 'employer',
        ]);
    }

    #[Test]
    public function a_funding_round_can_be_created_for_an_organization(): void
    {
        $organization = $this->makeOrganization();

        $round = FundingRound::create([
            'organization_id' => $organization->id,
            'round_name' => 'Series A',
            'round_date' => '2023-01-15',
            'amount_raised' => 5_000_000_000,
            'currency' => 'USD',
        ]);

        $this->assertCount(1, $organization->fundingRounds);
        $this->assertSame($organization->id, $round->organization->id);
    }

    #[Test]
    public function funding_round_amounts_persist_as_integer_cents(): void
    {
        $organization = $this->makeOrganization();

        $round = FundingRound::create([
            'organization_id' => $organization->id,
            'round_name' => 'Seed',
            'amount_raised' => 25_000_000,
        ]);

        $round->refresh();

        $this->assertSame(25_000_000, $round->amount_raised);
        $this->assertIsInt($round->amount_raised);
    }

    #[Test]
    public function money_helper_formats_amount_raised_for_display(): void
    {
        $organization = $this->makeOrganization();

        $round = FundingRound::create([
            'organization_id' => $organization->id,
            'round_name' => 'Series B',
            'amount_raised' => 7_000_000_000,
        ]);

        $this->assertSame('70000000.00', Money::format($round->amount_raised));
    }

    #[Test]
    public function amount_raised_is_nullable(): void
    {
        $organization = $this->makeOrganization();

        $round = FundingRound::create([
            'organization_id' => $organization->id,
            'round_name' => 'Bootstrapped',
            'amount_raised' => null,
        ]);

        $round->refresh();

        $this->assertNull($round->amount_raised);
        $this->assertNull(Money::format($round->amount_raised));
    }
}