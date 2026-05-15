<?php

namespace Tests\Unit\Services\Resolution;

use App\Models\Tag;
use App\Models\TagAlias;
use App\Services\Resolution\TagResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the TagResolver service. Covers both the side-effectful
 * resolve() method (used at draft confirm time) and the read-only
 * preview() method (used at review-screen render time to show the user
 * what would happen for each AI-extracted tag).
 */
class TagResolverTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────
    // resolve() — finds or creates
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function resolve_returns_existing_tag_on_canonical_name_match(): void
    {
        $existing = Tag::create(['name' => 'Python', 'category' => 'language']);

        $result = (new TagResolver())->resolve('Python');

        $this->assertSame($existing->id, $result->id);
        $this->assertSame(1, Tag::count());
    }

    #[Test]
    public function resolve_name_match_is_case_insensitive(): void
    {
        $existing = Tag::create(['name' => 'Python', 'category' => 'language']);

        $result = (new TagResolver())->resolve('python');

        $this->assertSame($existing->id, $result->id);
        $this->assertSame(1, Tag::count());
    }

    #[Test]
    public function resolve_returns_aliased_tag_when_alias_matches(): void
    {
        $tag = Tag::create(['name' => 'PostgreSQL', 'category' => 'tool']);
        $tag->aliases()->create(['alias' => 'postgres']);

        $result = (new TagResolver())->resolve('postgres');

        $this->assertSame($tag->id, $result->id);
        $this->assertSame(1, Tag::count());
    }

    #[Test]
    public function resolve_alias_match_is_case_insensitive(): void
    {
        $tag = Tag::create(['name' => 'PostgreSQL', 'category' => 'tool']);
        $tag->aliases()->create(['alias' => 'postgres']);

        $result = (new TagResolver())->resolve('POSTGRES');

        $this->assertSame($tag->id, $result->id);
    }

    #[Test]
    public function resolve_creates_a_new_tag_when_no_match_exists(): void
    {
        $result = (new TagResolver())->resolve('Kubernetes');

        $this->assertSame(1, Tag::count());
        $this->assertSame('Kubernetes', $result->name);
        $this->assertNull($result->category);
    }

    #[Test]
    public function resolve_preserves_ai_casing_when_creating_new(): void
    {
        // The AI emits a particular casing; we keep it. Subsequent
        // extractions of the same name (in any case) resolve to this
        // record case-insensitively.
        $first = (new TagResolver())->resolve('CamelCase Thing');
        $second = (new TagResolver())->resolve('camelcase thing');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('CamelCase Thing', $first->name);
    }

    #[Test]
    public function resolve_trims_whitespace_before_lookup_and_create(): void
    {
        $existing = Tag::create(['name' => 'Python', 'category' => 'language']);

        $result = (new TagResolver())->resolve('  Python  ');

        $this->assertSame($existing->id, $result->id);
    }

    // ────────────────────────────────────────────────────────────
    // preview() — read-only lookup
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function preview_returns_existing_status_for_canonical_name_match(): void
    {
        $tag = Tag::create(['name' => 'Python', 'category' => 'language']);

        $result = (new TagResolver())->preview('python');

        $this->assertSame('existing', $result['status']);
        $this->assertSame($tag->id, $result['tag']->id);
        $this->assertNull($result['matched_alias']);
    }

    #[Test]
    public function preview_returns_alias_status_with_matched_alias_name(): void
    {
        $tag = Tag::create(['name' => 'PostgreSQL', 'category' => 'tool']);
        $tag->aliases()->create(['alias' => 'postgres']);

        $result = (new TagResolver())->preview('postgres');

        $this->assertSame('alias', $result['status']);
        $this->assertSame($tag->id, $result['tag']->id);
        $this->assertSame('postgres', $result['matched_alias']);
    }

    #[Test]
    public function preview_returns_new_status_when_no_match(): void
    {
        $result = (new TagResolver())->preview('Brand New Tag');

        $this->assertSame('new', $result['status']);
        $this->assertNull($result['tag']);
        $this->assertNull($result['matched_alias']);
    }

    #[Test]
    public function preview_does_not_create_any_records(): void
    {
        (new TagResolver())->preview('Nothing Here');
        (new TagResolver())->preview('Also Nothing');
        (new TagResolver())->preview('Definitely Not Created');

        $this->assertSame(0, Tag::count());
        $this->assertSame(0, TagAlias::count());
    }
}