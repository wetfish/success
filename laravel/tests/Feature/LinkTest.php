<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);
    }

    #[Test]
    public function a_link_can_be_attached_to_an_organization(): void
    {
        $organization = $this->makeOrganization();

        $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
            'title' => 'Test Co',
        ]);

        $this->assertCount(1, $organization->links);
        $this->assertSame('Test Co', $organization->links->first()->title);
    }

    #[Test]
    public function the_inverse_morphto_relationship_resolves_correctly(): void
    {
        $organization = $this->makeOrganization();

        $link = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $this->assertInstanceOf(Organization::class, $link->linkable);
        $this->assertSame($organization->id, $link->linkable->id);
    }

    #[Test]
    public function is_personal_appearance_defaults_to_false(): void
    {
        $organization = $this->makeOrganization();

        $link = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $this->assertFalse($link->is_personal_appearance);
        $this->assertIsBool($link->is_personal_appearance);
    }

    #[Test]
    public function is_personal_appearance_can_be_set_to_true(): void
    {
        $project = Project::create([
            'organization_id' => $this->makeOrganization()->id,
            'name' => 'Test Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $link = $project->links()->create([
            'type' => 'media_appearance',
            'url' => 'https://youtube.com/watch?v=abc',
            'title' => 'Conference talk',
            'is_personal_appearance' => true,
        ]);

        $this->assertTrue($link->is_personal_appearance);
    }

    #[Test]
    public function links_of_most_types_require_a_url(): void
    {
        $organization = $this->makeOrganization();

        $this->expectException(InvalidArgumentException::class);

        $organization->links()->create([
            'type' => 'website',
            'url' => null,
            'title' => 'Test',
        ]);
    }

    #[Test]
    public function internal_doc_links_may_have_null_url_but_require_title(): void
    {
        $organization = $this->makeOrganization();

        // This should succeed — internal_doc with title, no url
        $link = $organization->links()->create([
            'type' => 'internal_doc',
            'url' => null,
            'title' => 'Confidential Architecture Doc',
        ]);

        $this->assertNotNull($link->id);
        $this->assertNull($link->url);
        $this->assertSame('Confidential Architecture Doc', $link->title);
    }

    #[Test]
    public function internal_doc_links_without_a_title_are_rejected(): void
    {
        $organization = $this->makeOrganization();

        $this->expectException(InvalidArgumentException::class);

        $organization->links()->create([
            'type' => 'internal_doc',
            'url' => null,
            'title' => null,
        ]);
    }

    #[Test]
    public function links_can_be_attached_to_different_polymorphic_types(): void
    {
        $organization = $this->makeOrganization();
        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Test Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $orgLink = $organization->links()->create([
            'type' => 'website',
            'url' => 'https://example.com',
        ]);

        $projectLink = $project->links()->create([
            'type' => 'repo',
            'url' => 'https://github.com/example/repo',
        ]);

        $this->assertSame(2, Link::count());
        $this->assertInstanceOf(Organization::class, $orgLink->linkable);
        $this->assertInstanceOf(Project::class, $projectLink->linkable);
    }
}