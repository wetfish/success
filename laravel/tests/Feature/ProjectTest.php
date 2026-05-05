<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectTest extends TestCase
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
    public function a_project_can_be_created(): void
    {
        $organization = $this->makeOrganization();

        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Lightning Terminal',
            'description' => 'Web dashboard for managing Lightning nodes',
            'visibility' => 'public',
            'contribution_level' => 'core',
            'date_precision' => 'month',
        ]);

        $this->assertSame('Lightning Terminal', $project->name);
        $this->assertSame('public', $project->visibility);
        $this->assertSame('core', $project->contribution_level);
    }

    #[Test]
    public function a_project_can_optionally_belong_to_a_position(): void
    {
        $organization = $this->makeOrganization();

        $position = Position::create([
            'organization_id' => $organization->id,
            'title' => 'Engineer',
            'employment_type' => 'full_time',
            'start_date' => '2022-01-01',
            'location_arrangement' => 'remote',
        ]);

        $project = Project::create([
            'organization_id' => $organization->id,
            'position_id' => $position->id,
            'name' => 'Some Project',
            'visibility' => 'internal',
            'contribution_level' => 'lead',
        ]);

        $this->assertSame($position->id, $project->position->id);
    }

    #[Test]
    public function a_project_can_have_child_projects(): void
    {
        $organization = $this->makeOrganization();

        $parent = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Lightning Terminal',
            'visibility' => 'public',
            'contribution_level' => 'core',
        ]);

        $child = Project::create([
            'organization_id' => $organization->id,
            'parent_project_id' => $parent->id,
            'name' => 'Notifications System',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $this->assertCount(1, $parent->childProjects);
        $this->assertSame('Notifications System', $parent->childProjects->first()->name);
        $this->assertSame($parent->id, $child->parentProject->id);
    }

    #[Test]
    public function a_subproject_must_belong_to_the_same_organization_as_its_parent(): void
    {
        $orgA = $this->makeOrganization();
        $orgB = Organization::create([
            'name' => 'Other Co',
            'type' => 'employer',
        ]);

        $parent = Project::create([
            'organization_id' => $orgA->id,
            'name' => 'Parent Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $this->expectException(InvalidArgumentException::class);

        Project::create([
            'organization_id' => $orgB->id,
            'parent_project_id' => $parent->id,
            'name' => 'Cross-Org Child',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);
    }

    /**
     * Regression test: when organization_id arrives as a string (which
     * happens whenever values come from HTTP form submission), the
     * validator must treat the string and integer representations as
     * equivalent. Previously this used strict equality and `1 !== "1"`
     * triggered a false mismatch.
     *
     * Note: refresh() is needed before asserting on the IDs because
     * Eloquent stores values as the type they were assigned. Strings
     * in, strings out — until the model is reloaded, at which point
     * the configured casts convert IDs back to integers.
     */
    #[Test]
    public function the_subproject_org_check_treats_string_and_int_ids_as_equivalent(): void
    {
        $organization = $this->makeOrganization();

        $parent = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Parent',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        // Pass IDs as strings, mimicking how values arrive from HTTP
        // form submission. The validator must not throw.
        $child = Project::create([
            'organization_id' => (string) $organization->id,
            'parent_project_id' => (string) $parent->id,
            'name' => 'Child',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $this->assertNotNull($child->id);

        // Refresh from DB so Eloquent's casts re-apply and parent_project_id
        // comes back as int rather than the string we passed in.
        $child->refresh();
        $this->assertSame($parent->id, $child->parent_project_id);
    }

    #[Test]
    public function deleting_a_parent_project_sets_parent_id_to_null_on_children(): void
    {
        $organization = $this->makeOrganization();

        $parent = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Parent',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $child = Project::create([
            'organization_id' => $organization->id,
            'parent_project_id' => $parent->id,
            'name' => 'Child',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $parent->forceDelete();
        $child->refresh();

        $this->assertNull($child->parent_project_id);
    }

    #[Test]
    public function a_project_can_capture_the_full_story_shape(): void
    {
        $organization = $this->makeOrganization();

        $project = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Tor Migration',
            'description' => 'Switched from port forwarding to Tor for node connectivity',
            'problem' => 'Port forwarding was unreliable and required user configuration',
            'constraints' => 'Could not use self-signed certs or centralized CA',
            'approach' => 'Use Tor onion services for NAT tunneling',
            'outcome' => 'Eliminated user-facing port forwarding requirements',
            'rationale' => 'Tor avoided trade-offs of HTTPS solutions',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);

        $this->assertSame('Port forwarding was unreliable and required user configuration', $project->problem);
        $this->assertSame('Use Tor onion services for NAT tunneling', $project->approach);
        $this->assertSame('Tor avoided trade-offs of HTTPS solutions', $project->rationale);
    }
}