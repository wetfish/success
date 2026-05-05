<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRules;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProjectController extends Controller
{
    /**
     * Create a project at the organization level — no position attached.
     * Used for personal projects, side work, or work that spanned
     * multiple positions at the same organization.
     */
    public function createForOrganization(Organization $organization): View
    {
        return view('projects.create', [
            'organization' => $organization,
            'position' => null,
            'parentProject' => null,
            'project' => new Project([
                'organization_id' => $organization->id,
            ]),
            ...self::dropdownData(),
        ]);
    }

    /**
     * Create a project under a specific position. The position's
     * organization is derived automatically.
     */
    public function createForPosition(Position $position): View
    {
        $position->load('organization');

        return view('projects.create', [
            'organization' => $position->organization,
            'position' => $position,
            'parentProject' => null,
            'project' => new Project([
                'organization_id' => $position->organization_id,
                'position_id' => $position->id,
            ]),
            ...self::dropdownData(),
        ]);
    }

    /**
     * Create a sub-project under an existing project. The parent's
     * organization and position are inherited automatically — sub-
     * projects must live in the same organization as their parent
     * (model-level validator enforces this).
     */
    public function createSubProject(Project $project): View
    {
        $project->load('organization', 'position');

        return view('projects.create', [
            'organization' => $project->organization,
            'position' => $project->position,
            'parentProject' => $project,
            'project' => new Project([
                'organization_id' => $project->organization_id,
                'position_id' => $project->position_id,
                'parent_project_id' => $project->id,
            ]),
            ...self::dropdownData(),
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = Project::create($request->validated());

        return redirect()
            ->route('projects.show', $project)
            ->with('status', "Project \"{$project->name}\" created.");
    }

    public function show(Project $project): View
    {
        $project->load('organization', 'position', 'parentProject');

        $childProjects = $project->childProjects()
            ->orderBy('name')
            ->get();

        return view('projects.show', [
            'project' => $project,
            'childProjects' => $childProjects,
        ]);
    }

    public function edit(Project $project): View
    {
        $project->load('organization', 'position', 'parentProject');

        return view('projects.edit', [
            'project' => $project,
            'organization' => $project->organization,
            'position' => $project->position,
            'parentProject' => $project->parentProject,
            ...self::dropdownData(),
        ]);
    }

    public function update(
        UpdateProjectRequest $request,
        Project $project,
    ): RedirectResponse {
        $project->update($request->validated());

        return redirect()
            ->route('projects.show', $project)
            ->with('status', "Project \"{$project->name}\" updated.");
    }

    public function destroy(Project $project): RedirectResponse
    {
        $name = $project->name;

        // Redirect destination depends on context: the parent project
        // if this was a sub-project, otherwise the position if attached
        // to one, otherwise the organization.
        $redirect = match (true) {
            $project->parent_project_id !== null
                => redirect()->route('projects.show', $project->parent_project_id),
            $project->position_id !== null
                => redirect()->route('positions.show', $project->position_id),
            default
                => redirect()->route('organizations.show', $project->organization_id),
        };

        $project->delete();

        return $redirect->with('status', "Project \"{$name}\" deleted.");
    }

    /**
     * Shared dropdown data passed to create and edit views.
     */
    private static function dropdownData(): array
    {
        return [
            'visibilities' => ProjectRules::VISIBILITIES,
            'statuses' => ProjectRules::STATUSES,
            'contributionLevels' => ProjectRules::CONTRIBUTION_LEVELS,
            'datePrecisions' => ProjectRules::DATE_PRECISIONS,
        ];
    }
}