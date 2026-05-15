<?php

namespace Tests\Unit\Services\Resolution;

use App\Models\Person;
use App\Services\Resolution\PersonResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the PersonResolver service. Simpler shape than
 * TagResolver since people don't have aliases.
 */
class PersonResolverTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────
    // resolve()
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function resolve_returns_existing_person_on_name_match(): void
    {
        $existing = Person::create(['name' => 'Sarah Chen']);

        $result = (new PersonResolver())->resolve('Sarah Chen');

        $this->assertSame($existing->id, $result->id);
        $this->assertSame(1, Person::count());
    }

    #[Test]
    public function resolve_match_is_case_insensitive(): void
    {
        $existing = Person::create(['name' => 'Sarah Chen']);

        $result = (new PersonResolver())->resolve('SARAH CHEN');

        $this->assertSame($existing->id, $result->id);
    }

    #[Test]
    public function resolve_creates_new_person_when_no_match(): void
    {
        $result = (new PersonResolver())->resolve('Brand New Person');

        $this->assertSame(1, Person::count());
        $this->assertSame('Brand New Person', $result->name);
    }

    #[Test]
    public function resolve_trims_whitespace(): void
    {
        $existing = Person::create(['name' => 'Sarah Chen']);

        $result = (new PersonResolver())->resolve('  Sarah Chen  ');

        $this->assertSame($existing->id, $result->id);
    }

    // ────────────────────────────────────────────────────────────
    // findByName()
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function find_by_name_returns_existing_person(): void
    {
        $person = Person::create(['name' => 'Alex']);

        $result = (new PersonResolver())->findByName('alex');

        $this->assertSame($person->id, $result?->id);
    }

    #[Test]
    public function find_by_name_returns_null_when_no_match(): void
    {
        $result = (new PersonResolver())->findByName('Nobody');

        $this->assertNull($result);
    }

    #[Test]
    public function find_by_name_does_not_create_anything(): void
    {
        (new PersonResolver())->findByName('Phantom');

        $this->assertSame(0, Person::count());
    }

    // ────────────────────────────────────────────────────────────
    // preview()
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function preview_returns_existing_status_when_person_matches(): void
    {
        $person = Person::create(['name' => 'Sarah Chen']);

        $result = (new PersonResolver())->preview('sarah chen');

        $this->assertSame('existing', $result['status']);
        $this->assertSame($person->id, $result['person']->id);
    }

    #[Test]
    public function preview_returns_new_status_when_no_match(): void
    {
        $result = (new PersonResolver())->preview('Phantom');

        $this->assertSame('new', $result['status']);
        $this->assertNull($result['person']);
    }

    #[Test]
    public function preview_does_not_create_any_records(): void
    {
        (new PersonResolver())->preview('Nobody');
        (new PersonResolver())->preview('Phantom');

        $this->assertSame(0, Person::count());
    }
}