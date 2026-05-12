<?php

namespace App\Http\Controllers;

use App\Http\Requests\LinkRules;
use App\Http\Requests\StoreLinkRequest;
use App\Http\Requests\UpdateLinkRequest;
use App\Models\Accomplishment;
use App\Models\Link;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Links are owned by one of several parent entity types (organizations,
 * projects, positions, accomplishments — and eventually people, which
 * lands with the Person UI slice). The controller exposes a create-in-
 * context route per parent type and a single polymorphic store endpoint.
 *
 * Edit, update, and destroy operate on the link record directly via the
 * non-nested `links/{link}` URL — the parent is recovered from the link's
 * `linkable` relationship rather than being part of the URL, which keeps
 * the route table flat as parent types are added.
 *
 * A link has no show page. Links display inline on their parent's show
 * page (via `resources/views/links/_section.blade.php` in chunk 2).
 */
class LinkController extends Controller
{
    /**
     * Maps the form-layer linkable_type alias to its Eloquent model
     * class. The aliases (rather than fully-qualified class names) live
     * in hidden form inputs; this map turns one into the other at the
     * controller boundary.
     *
     * When a new parent type starts accepting links (e.g. Person, when
     * the Person UI slice lands), add it here and add a corresponding
     * createFor* method below.
     */
    private const LINKABLE_MAP = [
        'organization' => Organization::class,
        'project' => Project::class,
        'position' => Position::class,
        'accomplishment' => Accomplishment::class,
    ];

    public function createForOrganization(Organization $organization): View
    {
        return view('links.create', [
            'linkable' => $organization,
            'linkableAlias' => 'organization',
            'link' => new Link(),
            ...self::viewContext($organization),
            ...self::dropdownDataFor('organization'),
        ]);
    }

    public function createForProject(Project $project): View
    {
        $project->load('organization');

        return view('links.create', [
            'linkable' => $project,
            'linkableAlias' => 'project',
            'link' => new Link(),
            ...self::viewContext($project),
            ...self::dropdownDataFor('project'),
        ]);
    }

    public function createForPosition(Position $position): View
    {
        $position->load('organization');

        return view('links.create', [
            'linkable' => $position,
            'linkableAlias' => 'position',
            'link' => new Link(),
            ...self::viewContext($position),
            ...self::dropdownDataFor('position'),
        ]);
    }

    public function createForAccomplishment(Accomplishment $accomplishment): View
    {
        $accomplishment->load('project.organization', 'position.organization');

        return view('links.create', [
            'linkable' => $accomplishment,
            'linkableAlias' => 'accomplishment',
            'link' => new Link(),
            ...self::viewContext($accomplishment),
            ...self::dropdownDataFor('accomplishment'),
        ]);
    }

    public function store(StoreLinkRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $linkableClass = self::LINKABLE_MAP[$validated['linkable_type']];
        $linkable = $linkableClass::findOrFail($validated['linkable_id']);

        $link = $linkable->links()->create([
            'type' => $validated['type'],
            'url' => $validated['url'] ?? null,
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_personal_appearance' => $validated['is_personal_appearance'] ?? false,
            'date' => $validated['date'] ?? null,
        ]);

        return redirect()
            ->to($this->showUrlFor($linkable))
            ->with('status', $this->createdStatus($link));
    }

    public function edit(Link $link): View
    {
        $link->load('linkable');
        $linkable = $link->linkable;
        $alias = self::aliasFor($linkable);

        // Applicable types for the parent, with the link's current type
        // forced in if it's somehow outside the applicable list. This
        // protects against the (rare) case where a link's type was set
        // through the AI pipeline or directly in the DB to a value that
        // the UI wouldn't normally offer for this parent — the user can
        // still see the current value and edit other fields without
        // being forced to change it.
        $applicableTypes = LinkRules::typesFor($alias);
        if ($link->type && ! in_array($link->type, $applicableTypes, true)) {
            $applicableTypes[] = $link->type;
        }

        return view('links.edit', [
            'linkable' => $linkable,
            'link' => $link,
            'types' => $applicableTypes,
            'typeLabels' => LinkRules::TYPE_LABELS,
            ...self::viewContext($linkable),
        ]);
    }

    public function update(UpdateLinkRequest $request, Link $link): RedirectResponse
    {
        $link->update($request->validated());

        $link->load('linkable');

        return redirect()
            ->to($this->showUrlFor($link->linkable))
            ->with('status', 'Link updated.');
    }

    public function destroy(Link $link): RedirectResponse
    {
        $link->load('linkable');
        $linkable = $link->linkable;

        $link->delete();

        return redirect()
            ->to($this->showUrlFor($linkable))
            ->with('status', 'Link deleted.');
    }

    /**
     * Resolve a polymorphic linkable to its show-page URL. Used by
     * store, update, and destroy to redirect the user back to the
     * parent record after a successful action.
     *
     * If the linkable is somehow null (e.g. its parent was hard-deleted
     * between when the link was loaded and when we redirect), fall back
     * to the organizations index so we never hit a route() error.
     */
    private function showUrlFor(?Model $linkable): string
    {
        return match (true) {
            $linkable instanceof Organization
                => route('organizations.show', $linkable),
            $linkable instanceof Project
                => route('projects.show', $linkable),
            $linkable instanceof Position
                => route('positions.show', $linkable),
            $linkable instanceof Accomplishment
                => route('accomplishments.show', $linkable),
            default
                => route('organizations.index'),
        };
    }

    /**
     * Reverse lookup against LINKABLE_MAP — given a model instance,
     * return the form alias used by the rule layer. Used on edit to
     * fetch the applicable types for the link's existing parent.
     */
    private static function aliasFor(Model $linkable): string
    {
        foreach (self::LINKABLE_MAP as $alias => $class) {
            if ($linkable instanceof $class) {
                return $alias;
            }
        }

        throw new InvalidArgumentException(
            'No linkable alias registered for class: ' . $linkable::class
        );
    }

    /**
     * Friendlier flash message after creating a link. Distinguishes
     * personal appearances (which the AI weights differently during
     * resume generation) from supporting links.
     */
    private function createdStatus(Link $link): string
    {
        return $link->is_personal_appearance
            ? 'Personal appearance added.'
            : 'Link added.';
    }

    /**
     * Build the dropdown data passed to create views. Scoped to a
     * specific parent alias so the type select only offers context-
     * appropriate options.
     */
    private static function dropdownDataFor(string $linkableAlias): array
    {
        return [
            'types' => LinkRules::typesFor($linkableAlias),
            'typeLabels' => LinkRules::TYPE_LABELS,
        ];
    }

    /**
     * Build the view header data — back URL, back label, and context
     * line — for whichever polymorphic parent a link is being attached
     * to or edited under. Both create and edit views render the same
     * three pieces of header chrome, so the match lives here rather
     * than being duplicated across the templates.
     */
    private static function viewContext(Model $linkable): array
    {
        return match (true) {
            $linkable instanceof Organization => [
                'backUrl' => route('organizations.show', $linkable),
                'backLabel' => $linkable->name,
                'context' => 'For organization: ' . $linkable->name,
            ],
            $linkable instanceof Project => [
                'backUrl' => route('projects.show', $linkable),
                'backLabel' => $linkable->name,
                'context' => 'For project: ' . $linkable->name,
            ],
            $linkable instanceof Position => [
                'backUrl' => route('positions.show', $linkable),
                'backLabel' => $linkable->title,
                'context' => 'For position: ' . $linkable->title
                    . ' at ' . $linkable->organization->name,
            ],
            $linkable instanceof Accomplishment => [
                'backUrl' => route('accomplishments.show', $linkable),
                'backLabel' => $linkable->title,
                'context' => 'For accomplishment: ' . $linkable->title,
            ],
        };
    }
}