<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccomplishmentRules;
use App\Http\Requests\StoreAccomplishmentRequest;
use App\Http\Requests\UpdateAccomplishmentRequest;
use App\Models\Accomplishment;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AccomplishmentController extends Controller
{
    /**
     * Create an accomplishment under a specific project. Most
     * accomplishments live under projects — they are evidence within
     * a body of work.
     */
    public function createForProject(Project $project): View
    {
        $project->load('organization', 'position');

        return view('accomplishments.create', [
            'project' => $project,
            'position' => null,
            'accomplishment' => new Accomplishment([
                'project_id' => $project->id,
                'confidence' => 3,
                'prominence' => 3,
            ]),
            ...self::dropdownData(),
        ]);
    }

    /**
     * Create an accomplishment under a specific position, not tied to
     * any project. Used for things like "got promoted," "mentored
     * juniors," "represented the team at conference X" — accomplishments
     * that belong to your role but aren't part of a discrete body of
     * project work.
     */
    public function createForPosition(Position $position): View
    {
        $position->load('organization');

        return view('accomplishments.create', [
            'project' => null,
            'position' => $position,
            'accomplishment' => new Accomplishment([
                'position_id' => $position->id,
                'confidence' => 3,
                'prominence' => 3,
            ]),
            ...self::dropdownData(),
        ]);
    }

    public function store(StoreAccomplishmentRequest $request): RedirectResponse
    {
        $accomplishment = Accomplishment::create($request->validated());

        return redirect()
            ->route('accomplishments.show', $accomplishment)
            ->with('status', 'Accomplishment created.');
    }

    public function show(Accomplishment $accomplishment): View
    {
        $accomplishment->load('project.organization', 'position.organization');

        return view('accomplishments.show', [
            'accomplishment' => $accomplishment,
        ]);
    }

    public function edit(Accomplishment $accomplishment): View
    {
        $accomplishment->load('project.organization', 'position.organization');

        return view('accomplishments.edit', [
            'accomplishment' => $accomplishment,
            'project' => $accomplishment->project,
            'position' => $accomplishment->position,
            ...self::dropdownData(),
        ]);
    }

    public function update(
        UpdateAccomplishmentRequest $request,
        Accomplishment $accomplishment,
    ): RedirectResponse {
        $accomplishment->update($request->validated());

        return redirect()
            ->route('accomplishments.show', $accomplishment)
            ->with('status', 'Accomplishment updated.');
    }

    public function destroy(Accomplishment $accomplishment): RedirectResponse
    {
        $redirect = $accomplishment->project_id !== null
            ? redirect()->route('projects.show', $accomplishment->project_id)
            : redirect()->route('positions.show', $accomplishment->position_id);

        $accomplishment->delete();

        return $redirect->with('status', 'Accomplishment deleted.');
    }

    private static function dropdownData(): array
    {
        return [
            'confidenceLabels' => AccomplishmentRules::CONFIDENCE_LABELS,
            'prominenceLabels' => AccomplishmentRules::PROMINENCE_LABELS,
        ];
    }
}