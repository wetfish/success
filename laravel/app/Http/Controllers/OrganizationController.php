<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrganizationRules;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
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
            'types' => OrganizationRules::TYPES,
            'statuses' => OrganizationRules::STATUSES,
        ]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        $organization = Organization::create($request->validated());

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
            'types' => OrganizationRules::TYPES,
            'statuses' => OrganizationRules::STATUSES,
        ]);
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization,
    ): RedirectResponse {
        $organization->update($request->validated());

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
}