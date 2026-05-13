<?php

namespace App\Http\Controllers;

use App\Http\Requests\PersonRules;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Models\Organization;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * People are the third deferred subsystem from milestone 3, landing
 * after links and tags. Unlike tags (no show page, edit doubles as
 * view) and like organizations (full resource), people have enough
 * meaningful relationship data — collaborator history across
 * positions, projects, and accomplishments — to warrant a dedicated
 * show page.
 *
 * Index is the "career directory" view: every person grouped by their
 * current organization. Show surfaces the relationship landscape:
 * where this person appears as a collaborator. Create and edit are
 * standard form flows.
 *
 * A quick-add flow lands in chunk 4 alongside the person picker. The
 * picker can create a name-only person inline during another form's
 * submission. The full create form here remains the canonical way to
 * fill in the rest of the metadata afterward.
 */
class PersonController extends Controller
{
    public function index(): View
    {
        // Eager-load the current organization since the index page
        // groups by it. Sort by name within each group; the grouping
        // is presentation logic the view handles via `groupBy`.
        $people = Person::query()
            ->with('currentOrganization')
            ->orderBy('name')
            ->get();

        return view('people.index', [
            'people' => $people,
            'totalCount' => $people->count(),
        ]);
    }

    public function create(): View
    {
        return view('people.create', [
            'person' => new Person(),
            ...self::dropdownData(),
        ]);
    }

    public function store(StorePersonRequest $request): RedirectResponse
    {
        $person = Person::create($request->validated());

        return redirect()
            ->route('people.show', $person)
            ->with('status', "Person \"{$person->name}\" created.");
    }

    public function show(Person $person): View
    {
        // Eager-load the relationship surface for the show page.
        // Each collaborator-table relationship carries its role on
        // the pivot, which the view renders alongside the parent
        // record's title. The current organization is a separate
        // belongsTo and gets its own load.
        $person->load([
            'currentOrganization',
            'positions.organization',
            'projects.organization',
            'accomplishments.project.organization',
            'accomplishments.position.organization',
            'links',
        ]);

        return view('people.show', [
            'person' => $person,
        ]);
    }

    public function edit(Person $person): View
    {
        return view('people.edit', [
            'person' => $person,
            ...self::dropdownData(),
        ]);
    }

    public function update(
        UpdatePersonRequest $request,
        Person $person,
    ): RedirectResponse {
        $person->update($request->validated());

        return redirect()
            ->route('people.show', $person)
            ->with('status', "Person \"{$person->name}\" updated.");
    }

    public function destroy(Person $person): RedirectResponse
    {
        // Soft-delete. The pivot rows in position_collaborators,
        // project_collaborators, and accomplishment_collaborators are
        // NOT touched by soft-delete — they continue to reference the
        // soft-deleted person. This is intentional: a soft-deleted
        // person can be restored without losing their collaboration
        // history. If the user force-deletes (via the DB or tinker),
        // the FK cascade does clean up those pivot rows.
        $name = $person->name;
        $person->delete();

        return redirect()
            ->route('people.index')
            ->with('status', "Person \"{$name}\" deleted.");
    }

    /**
     * JSON autocomplete endpoint used by the person picker. Returns up
     * to 5 people matching the query string, ranked by match quality:
     *
     *   1. Name prefix
     *   2. Name substring
     *
     * Within each tier, alphabetical. The response includes current
     * title and current organization name so the picker dropdown can
     * disambiguate two people with similar names — same role as
     * `matched_alias` does for tags, but always-present rather than
     * conditional on an alias match.
     *
     * Soft-deleted people are excluded automatically by the model's
     * SoftDeletes global scope.
     *
     * Empty or whitespace-only queries return an empty array — the
     * picker is responsible for not firing the request until the user
     * has typed at least one character. Mirrors the tag search
     * endpoint's contract.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $like = '%' . addcslashes($query, '%_\\') . '%';
        $lowerQuery = mb_strtolower($query);

        // Single query: people whose name matches. Eager-load the
        // current organization so accessing its name in the response
        // doesn't N+1 across the result set.
        $candidates = Person::query()
            ->with('currentOrganization')
            ->where('name', 'LIKE', $like)
            ->get();

        // Two-tier scoring. Lower tier = better match.
        $scored = $candidates->map(function (Person $person) use ($lowerQuery) {
            $name = mb_strtolower($person->name);
            $tier = match (true) {
                str_starts_with($name, $lowerQuery) => 1,
                str_contains($name, $lowerQuery) => 2,
                default => PHP_INT_MAX,
            };

            return [
                'person' => $person,
                'tier' => $tier,
            ];
        });

        // Stable two-pass sort: name first (alphabetical tiebreaker),
        // then tier (dominant key). Laravel collection sorts preserve
        // order for equal keys.
        $ranked = $scored
            ->sortBy(fn (array $row) => mb_strtolower($row['person']->name))
            ->sortBy('tier')
            ->take(5)
            ->values();

        return response()->json($ranked->map(fn (array $row) => [
            'id' => $row['person']->id,
            'name' => $row['person']->name,
            'current_title' => $row['person']->current_title,
            'current_organization_name' => $row['person']->currentOrganization?->name,
        ])->all());
    }

    private static function dropdownData(): array
    {
        return [
            'relationshipTypes' => PersonRules::RELATIONSHIP_TYPES,
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
        ];
    }
}