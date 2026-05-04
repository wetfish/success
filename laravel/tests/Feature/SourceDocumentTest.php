<?php

namespace Tests\Feature;

use App\Models\Accomplishment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SourceDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Test Co',
            'type' => 'employer',
        ]);
    }

    private function makeProject(Organization $organization): Project
    {
        return Project::create([
            'organization_id' => $organization->id,
            'name' => 'Test Project',
            'visibility' => 'public',
            'contribution_level' => 'lead',
        ]);
    }

    #[Test]
    public function a_source_document_can_be_created(): void
    {
        $sourceDocument = SourceDocument::create([
            'title' => 'Lightning Labs project notes',
            'kind' => 'interview_prep',
            'body' => 'Some raw notes about projects I worked on...',
            'context_date' => '2025-07-15',
            'context_notes' => 'Interview prep for Stripe',
        ]);

        $this->assertSame('interview_prep', $sourceDocument->kind);
        $this->assertSame('Lightning Labs project notes', $sourceDocument->title);
    }

    #[Test]
    public function a_source_document_can_link_to_multiple_accomplishments(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $accomplishment1 = Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'First derived accomplishment',
            'date' => '2023-04-01',
        ]);

        $accomplishment2 = Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Second derived accomplishment',
            'date' => '2023-08-01',
        ]);

        $sourceDocument = SourceDocument::create([
            'kind' => 'brag_doc',
            'body' => 'Notes describing both accomplishments',
        ]);

        $sourceDocument->accomplishments()->attach([$accomplishment1->id, $accomplishment2->id]);

        $this->assertCount(2, $sourceDocument->accomplishments);

        // Inverse — accomplishments know their source document
        $this->assertCount(1, $accomplishment1->sourceDocuments);
        $this->assertSame($sourceDocument->id, $accomplishment1->sourceDocuments->first()->id);
    }

    #[Test]
    public function a_source_document_can_link_to_projects(): void
    {
        $organization = $this->makeOrganization();
        $project = $this->makeProject($organization);

        $sourceDocument = SourceDocument::create([
            'kind' => 'performance_review',
            'body' => 'Notes about the project',
        ]);

        $sourceDocument->projects()->attach($project);

        $this->assertCount(1, $sourceDocument->projects);
        $this->assertCount(1, $project->sourceDocuments);
    }

    #[Test]
    public function deleting_a_source_document_cascades_through_join_tables(): void
    {
        $project = $this->makeProject($this->makeOrganization());

        $accomplishment = Accomplishment::create([
            'project_id' => $project->id,
            'description' => 'Derived from notes',
            'date' => '2023-04-01',
        ]);

        $sourceDocument = SourceDocument::create([
            'kind' => 'brag_doc',
            'body' => 'Notes',
        ]);

        $sourceDocument->accomplishments()->attach($accomplishment);
        $sourceDocument->projects()->attach($project);

        $this->assertDatabaseCount('accomplishment_source_documents', 1);
        $this->assertDatabaseCount('project_source_documents', 1);

        $sourceDocument->forceDelete();

        $this->assertDatabaseCount('accomplishment_source_documents', 0);
        $this->assertDatabaseCount('project_source_documents', 0);

        // Source records themselves are unaffected
        $this->assertDatabaseHas('accomplishments', ['id' => $accomplishment->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }
}