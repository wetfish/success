<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PersonTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_person_can_be_created(): void
    {
        $person = Person::create([
            'name' => 'Alex Reviewer',
            'current_title' => 'VP of Engineering',
            'email' => 'alex@example.com',
            'relationship_type' => 'manager',
        ]);

        $this->assertDatabaseHas('people', [
            'name' => 'Alex Reviewer',
            'relationship_type' => 'manager',
        ]);
    }

    #[Test]
    public function a_person_can_belong_to_a_current_organization(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        $person = Person::create([
            'name' => 'Alex Reviewer',
            'current_organization_id' => $organization->id,
        ]);

        $this->assertSame($organization->id, $person->currentOrganization->id);
    }

    #[Test]
    public function a_person_without_an_organization_returns_null_for_the_relationship(): void
    {
        $person = Person::create([
            'name' => 'Independent Person',
        ]);

        $this->assertNull($person->currentOrganization);
    }

    #[Test]
    public function a_person_can_have_links(): void
    {
        $person = Person::create([
            'name' => 'Alex Reviewer',
        ]);

        $person->links()->create([
            'type' => 'linkedin',
            'url' => 'https://linkedin.com/in/alex',
        ]);

        $this->assertCount(1, $person->links);
        $this->assertSame('linkedin', $person->links->first()->type);
    }

    #[Test]
    public function force_deleting_an_organization_sets_persons_organization_to_null(): void
    {
        $organization = Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);

        $person = Person::create([
            'name' => 'Alex Reviewer',
            'current_organization_id' => $organization->id,
        ]);

        $organization->forceDelete();

        $person->refresh();
        $this->assertNull($person->current_organization_id);
    }
}