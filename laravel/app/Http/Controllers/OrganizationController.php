<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(): View
    {
        $organizations = Organization::orderBy('name')->get();

        return view('organizations.index', [
            'organizations' => $organizations,
        ]);
    }

    public function create(): View
    {
        return view('organizations.create', [
            'organization' => new Organization(),
            'types' => OrganizationType::cases(),
            'statuses' => OrganizationStatus::cases(),
        ]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        // tag_ids is part of the validated payload but isn't an
        // attribute on the organization itself — separate it out
        // before mass-assignment, then sync after the record exists.
        $organization = Organization::create($request->safe()->except('tag_ids'));
        $organization->tags()->sync($request->input('tag_ids', []));

        return redirect()
            ->route('organizations.show', $organization)
            ->with('status', "Organization \"{$organization->name}\" created.");
    }

    public function show(Organization $organization): View
    {
        return view('organizations.show', [
            'organization' => $organization,
        ]);
    }

    public function edit(Organization $organization): View
    {
        return view('organizations.edit', [
            'organization' => $organization,
            'types' => OrganizationType::cases(),
            'statuses' => OrganizationStatus::cases(),
        ]);
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization,
    ): RedirectResponse {
        $organization->update($request->safe()->except('tag_ids'));
        $organization->tags()->sync($request->input('tag_ids', []));

        return redirect()
            ->route('organizations.show', $organization)
            ->with('status', "Organization \"{$organization->name}\" updated.");
    }

    public function destroy(Organization $organization): RedirectResponse
    {
        $name = $organization->name;
        $organization->delete();

        return redirect()
            ->route('organizations.index')
            ->with('status', "Organization \"{$name}\" deleted.");
    }

    /**
     * JSON search endpoint for the org picker autocomplete.
     * Two-tier ranking: prefix matches first, then substring.
     * Returns id, name, type, and tagline for disambiguation.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $like = '%' . addcslashes($query, '%_\\') . '%';
        $lowerQuery = mb_strtolower($query);

        $candidates = Organization::query()
            ->where('name', 'LIKE', $like)
            ->get();

        $scored = $candidates->map(function (Organization $org) use ($lowerQuery) {
            $name = mb_strtolower($org->name);
            $tier = match (true) {
                str_starts_with($name, $lowerQuery) => 1,
                str_contains($name, $lowerQuery) => 2,
                default => PHP_INT_MAX,
            };

            return [
                'org' => $org,
                'tier' => $tier,
            ];
        });

        $ranked = $scored
            ->sortBy(fn (array $row) => mb_strtolower($row['org']->name))
            ->sortBy('tier')
            ->take(5)
            ->values();

        return response()->json($ranked->map(fn (array $row) => [
            'id' => $row['org']->id,
            'name' => $row['org']->name,
            'type' => str_replace('_', ' ', $row['org']->type),
            'tagline' => $row['org']->tagline,
        ])->all());
    }

    /**
     * Quick-create a prospect organization from the org picker.
     * Accepts only a name; type is forced to 'prospect'. Returns
     * the new org's id and name as JSON so the picker can select it
     * immediately.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $organization = Organization::create([
            'name' => trim($request->input('name')),
            'type' => OrganizationType::Prospect->value,
        ]);

        return response()->json([
            'id' => $organization->id,
            'name' => $organization->name,
        ], 201);
    }
}