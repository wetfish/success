<?php

namespace App\Http\Controllers;

use App\Http\Requests\PositionRules;
use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Models\Organization;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PositionController extends Controller
{
    /**
     * Show the create form scoped to a specific organization. Positions
     * are always created in the context of an organization, so there is
     * no top-level "add position" entry point — only this nested route.
     */
    public function create(Organization $organization): View
    {
        return view('positions.create', [
            'organization' => $organization,
            'position' => new Position(['organization_id' => $organization->id]),
            'employmentTypes' => PositionRules::EMPLOYMENT_TYPES,
            'locationArrangements' => PositionRules::LOCATION_ARRANGEMENTS,
            'reasonsForLeaving' => PositionRules::REASONS_FOR_LEAVING,
        ]);
    }

    public function store(StorePositionRequest $request): RedirectResponse
    {
        $position = Position::create($request->validated());

        return redirect()
            ->route('positions.show', $position)
            ->with('status', "Position \"{$position->title}\" created.");
    }

    public function show(Position $position): View
    {
        $position->load('organization');

        return view('positions.show', [
            'position' => $position,
        ]);
    }

    public function edit(Position $position): View
    {
        $position->load('organization');

        return view('positions.edit', [
            'position' => $position,
            'organization' => $position->organization,
            'employmentTypes' => PositionRules::EMPLOYMENT_TYPES,
            'locationArrangements' => PositionRules::LOCATION_ARRANGEMENTS,
            'reasonsForLeaving' => PositionRules::REASONS_FOR_LEAVING,
        ]);
    }

    public function update(
        UpdatePositionRequest $request,
        Position $position,
    ): RedirectResponse {
        $position->update($request->validated());

        return redirect()
            ->route('positions.show', $position)
            ->with('status', "Position \"{$position->title}\" updated.");
    }

    public function destroy(Position $position): RedirectResponse
    {
        $title = $position->title;
        $organizationId = $position->organization_id;
        $position->delete();

        return redirect()
            ->route('organizations.show', $organizationId)
            ->with('status', "Position \"{$title}\" deleted.");
    }
}