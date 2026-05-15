<?php

namespace App\Services\Drafts;

use App\Models\Accomplishment;
use App\Models\ExtractedRecord;
use App\Models\Link;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Position;
use App\Models\Project;
use App\Services\Resolution\PersonResolver;
use App\Services\Resolution\TagResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Converts a pending ExtractedRecord into a real catalog record
 * (Organization, Position, Project, Accomplishment, Person, or Link).
 *
 * The draft's payload is a flat array of field values produced by
 * the AI at extraction time. Parent references — organization,
 * position, project — are stored as name strings in the payload
 * because at extraction time the parent doesn't exist as a database
 * row yet. Confirmation resolves those strings to foreign-key IDs
 * by looking up exact-name (case-insensitive) matches in the
 * existing catalog.
 *
 * Entity drafts (organization, position, project, accomplishment)
 * may also carry nested `tags` and `collaborators` arrays. These get
 * materialized as pivot rows after the parent record is created.
 * Tags resolve against existing tag names or aliases (case-insensitive)
 * and auto-create when no match exists. Collaborators resolve against
 * existing people by name (case-insensitive) and auto-create when no
 * match exists. This mirrors the AI's "every mention" output shape —
 * a person mentioned in a collaborator slot doesn't need to already
 * exist as a top-level Person record.
 *
 * If a parent reference can't be resolved, throws DraftConfirmationException
 * with a user-facing message. The controller catches this and
 * surfaces it as a flash message; no rows are created.
 *
 * The whole confirmation (real record creation + nested attachments +
 * draft status update) runs in a transaction so a partial state is
 * impossible.
 *
 * Duplicate detection — "this org draft probably matches an existing
 * Lightning Labs Inc record, do you want to merge?" — is NOT in
 * scope here. That's slice 4.5. This service does exact-name lookups
 * only; if you want fuzzy matching, build on top of this.
 */
class DraftConfirmer
{
    private TagResolver $tagResolver;
    private PersonResolver $personResolver;

    /**
     * Constructor accepts resolver instances via DI but defaults to
     * fresh instantiation if not provided. The resolvers are
     * stateless and cheap to construct, so default instantiation
     * produces the same behavior as injection — this keeps existing
     * `new DraftConfirmer()` call sites (notably in tests) working
     * without forcing every caller to pass resolvers.
     */
    public function __construct(
        ?TagResolver $tagResolver = null,
        ?PersonResolver $personResolver = null,
    ) {
        $this->tagResolver = $tagResolver ?? new TagResolver();
        $this->personResolver = $personResolver ?? new PersonResolver();
    }

    /**
     * Confirm a pending draft. Creates the corresponding real record
     * and marks the draft as confirmed with match_record_* pointing
     * at the new record.
     *
     * @return Model  the newly created catalog record
     *
     * @throws DraftConfirmationException when a parent reference
     *         can't be resolved or the draft is not pending
     */
    public function confirm(ExtractedRecord $draft): Model
    {
        if (! $draft->isPending()) {
            throw new DraftConfirmationException(
                'This draft has already been reviewed.'
            );
        }

        try {
            return DB::transaction(function () use ($draft) {
                $record = match ($draft->record_type) {
                    'organization' => $this->confirmOrganization($draft),
                    'position' => $this->confirmPosition($draft),
                    'project' => $this->confirmProject($draft),
                    'accomplishment' => $this->confirmAccomplishment($draft),
                    'person' => $this->confirmPerson($draft),
                    default => throw new DraftConfirmationException(
                        "Unknown record type: {$draft->record_type}"
                    ),
                };

                $draft->update([
                    'status' => 'confirmed',
                    'match_record_type' => $draft->record_type,
                    'match_record_id' => $record->id,
                ]);

                return $record;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Convert raw DB errors to user-facing messages. The most
            // common cause is a missing NOT NULL field — for example,
            // the AI extracted a position draft without start_date or
            // employment_type. Surface that to the user rather than
            // leaking SQL state codes.
            throw new DraftConfirmationException(
                "Can't confirm this {$draft->record_type} — the draft is missing " .
                "one or more required fields. Try editing the draft first, " .
                "or create the record manually. (Database error: {$e->getMessage()})"
            );
        } catch (\InvalidArgumentException $e) {
            // Model-level invariant violations — for example, an
            // accomplishment without a date or period_start, or a
            // confidence value outside 1-5. The models raise these
            // with already-readable messages, so we pass them
            // through directly.
            throw new DraftConfirmationException(
                "Can't confirm this {$draft->record_type}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Organization confirmation is the simplest case for parent
     * resolution — no parent references to resolve. Map fillable
     * payload fields to the model, create, then attach any nested
     * tags. Organizations are not collaborator-bearing (collaborators
     * live on positions, projects, and accomplishments only).
     */
    private function confirmOrganization(ExtractedRecord $draft): Organization
    {
        $payload = $draft->payload ?? [];
        $organization = Organization::create(
            $this->filterFillable($payload, Organization::class)
        );

        $this->attachNestedTags($organization, $draft);
        $this->attachNestedLinks($organization, $draft);

        return $organization;
    }

    /**
     * Position requires organization_id, resolved from the
     * organization_name field in the payload. May carry nested
     * `tags` and `collaborators` arrays that get attached after
     * the position itself is created.
     */
    private function confirmPosition(ExtractedRecord $draft): Position
    {
        $payload = $draft->payload ?? [];

        $orgName = $payload['organization_name'] ?? null;
        if (! $orgName) {
            throw new DraftConfirmationException(
                'This position is missing an organization name in its payload.'
            );
        }

        $organization = $this->findOrganizationByName($orgName);
        if (! $organization) {
            throw new DraftConfirmationException(
                "Can't confirm this position — the organization \"{$orgName}\" " .
                "isn't in your catalog yet. Confirm the organization draft " .
                "first, or add the organization manually."
            );
        }

        $attributes = $this->filterFillable($payload, Position::class);
        $attributes['organization_id'] = $organization->id;

        $position = Position::create($attributes);

        $this->attachNestedTags($position, $draft);
        $this->attachNestedCollaborators($position, $draft, 'role_on_position');
        $this->attachNestedLinks($position, $draft);

        return $position;
    }

    /**
     * Project belongs to an organization, and optionally to a
     * position (the common case) and/or a parent project (nested
     * projects). The payload may have organization_name,
     * position_title, and parent_project_name; resolve each to
     * an FK if present.
     */
    private function confirmProject(ExtractedRecord $draft): Project
    {
        $payload = $draft->payload ?? [];

        $orgName = $payload['organization_name'] ?? null;
        if (! $orgName) {
            throw new DraftConfirmationException(
                'This project is missing an organization name in its payload.'
            );
        }

        $organization = $this->findOrganizationByName($orgName);
        if (! $organization) {
            throw new DraftConfirmationException(
                "Can't confirm this project — the organization \"{$orgName}\" " .
                "isn't in your catalog yet. Confirm the organization draft first."
            );
        }

        $attributes = $this->filterFillable($payload, Project::class);
        $attributes['organization_id'] = $organization->id;

        // Position is optional. When present, look it up by org+title.
        if (! empty($payload['position_title'])) {
            $position = $this->findPositionByRef($organization, $payload['position_title']);
            if (! $position) {
                throw new DraftConfirmationException(
                    "Can't confirm this project — the position " .
                    "\"{$payload['position_title']}\" at \"{$orgName}\" " .
                    "isn't in your catalog yet. Confirm the position draft first."
                );
            }
            $attributes['position_id'] = $position->id;
        }

        // Parent project is also optional (sub-projects). Look up by
        // name within the same organization.
        if (! empty($payload['parent_project_name'])) {
            $parent = $this->findProjectByName($organization, $payload['parent_project_name']);
            if (! $parent) {
                throw new DraftConfirmationException(
                    "Can't confirm this project — the parent project " .
                    "\"{$payload['parent_project_name']}\" isn't in your " .
                    "catalog yet. Confirm the parent project first."
                );
            }
            $attributes['parent_project_id'] = $parent->id;
        }

        $project = Project::create($attributes);

        $this->attachNestedTags($project, $draft);
        $this->attachNestedCollaborators($project, $draft, 'role_on_project');
        $this->attachNestedLinks($project, $draft);

        return $project;
    }

    /**
     * Accomplishment attaches to either a project (the common case —
     * accomplishments are evidence within projects) or directly to
     * a position (for things like promotions, mentoring, role-level
     * achievements). The payload's project_name decides which path.
     */
    /**
     * Accomplishment attaches to either a project (the common case —
     * accomplishments are evidence within projects) or directly to
     * a position (for things like promotions, mentoring, role-level
     * achievements). The payload's project_name decides which path.
     *
     * organization_name is optional in the project-attached branch.
     * The AI sometimes omits it when project_name alone uniquely
     * identifies the parent. When org_name IS present, it scopes
     * the project lookup to that org (handles the rare case where
     * two orgs have a project with the same name). When org_name is
     * absent, we look up the project globally and require a unique
     * match — ambiguous lookups (2+ projects with the same name) ask
     * the user to add org_name to disambiguate.
     *
     * organization_name IS required in the position-attached branch.
     * Positions are identified by org+title, so without an org we
     * can't disambiguate (and a bare position title like "Engineer"
     * is almost certain to match multiple orgs).
     */
    private function confirmAccomplishment(ExtractedRecord $draft): Accomplishment
    {
        $payload = $draft->payload ?? [];
        $attributes = $this->filterFillable($payload, Accomplishment::class);

        $hasProjectName = ! empty($payload['project_name']);
        $hasPositionTitle = ! empty($payload['position_title']);

        if (! $hasProjectName && ! $hasPositionTitle) {
            throw new DraftConfirmationException(
                'This accomplishment is missing both a project and a position ' .
                'reference in its payload. At least one is required.'
            );
        }

        if ($hasProjectName) {
            $project = $this->resolveProjectForAccomplishment(
                projectName: $payload['project_name'],
                orgName: $payload['organization_name'] ?? null,
            );
            $attributes['project_id'] = $project->id;
        } else {
            // Position branch — org_name is required for unambiguous lookup.
            $orgName = $payload['organization_name'] ?? null;
            if (! $orgName) {
                throw new DraftConfirmationException(
                    "Can't confirm this accomplishment — it references a " .
                    "position by title but doesn't include an organization " .
                    "name. Add organization_name to the payload to disambiguate."
                );
            }

            $organization = $this->findOrganizationByName($orgName);
            if (! $organization) {
                throw new DraftConfirmationException(
                    "Can't confirm this accomplishment — the organization " .
                    "\"{$orgName}\" isn't in your catalog yet. Confirm the " .
                    "organization draft first."
                );
            }

            $position = $this->findPositionByRef($organization, $payload['position_title']);
            if (! $position) {
                throw new DraftConfirmationException(
                    "Can't confirm this accomplishment — the position " .
                    "\"{$payload['position_title']}\" at \"{$orgName}\" " .
                    "isn't in your catalog yet. Confirm the position draft first."
                );
            }
            $attributes['position_id'] = $position->id;
        }

        $accomplishment = Accomplishment::create($attributes);

        $this->attachNestedTags($accomplishment, $draft);
        $this->attachNestedCollaborators($accomplishment, $draft, 'role_on_accomplishment');
        $this->attachNestedLinks($accomplishment, $draft);

        return $accomplishment;
    }

    /**
     * Resolve a project for an accomplishment. If an organization name
     * is provided, scope the lookup; otherwise look globally and require
     * a unique match.
     *
     * @throws DraftConfirmationException when the project isn't found,
     *         the named organization doesn't exist, or a global lookup
     *         returns multiple matches
     */
    private function resolveProjectForAccomplishment(string $projectName, ?string $orgName): Project
    {
        if ($orgName) {
            $organization = $this->findOrganizationByName($orgName);
            if (! $organization) {
                throw new DraftConfirmationException(
                    "Can't confirm this accomplishment — the organization " .
                    "\"{$orgName}\" isn't in your catalog yet. Confirm the " .
                    "organization draft first."
                );
            }

            $project = $this->findProjectByName($organization, $projectName);
            if (! $project) {
                throw new DraftConfirmationException(
                    "Can't confirm this accomplishment — the project " .
                    "\"{$projectName}\" at \"{$orgName}\" isn't in your " .
                    "catalog yet. Confirm the project draft first."
                );
            }
            return $project;
        }

        // No org context — look up the project globally by name.
        $matches = Project::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($projectName))])
            ->get();

        if ($matches->isEmpty()) {
            throw new DraftConfirmationException(
                "Can't confirm this accomplishment — no project named " .
                "\"{$projectName}\" is in your catalog yet. Confirm the " .
                "project draft first."
            );
        }

        if ($matches->count() > 1) {
            throw new DraftConfirmationException(
                "Can't confirm this accomplishment — multiple projects " .
                "are named \"{$projectName}\" across different organizations. " .
                "Add organization_name to the payload to specify which one."
            );
        }

        return $matches->first();
    }

    /**
     * Person confirmation. The payload may include
     * `current_organization_name` referencing an existing organization;
     * if present, resolve to FK. Everything else is fillable.
     *
     * If a person with the same name already exists in the catalog
     * (case-insensitive match), the existing record is reused rather
     * than duplicated. This handles the common case where a person
     * was auto-created during an earlier collaborator-resolution step
     * before the standalone Person draft was confirmed. We don't
     * overwrite the existing person's fields — the user can edit
     * manually if they want the AI's richer data merged in.
     */
    private function confirmPerson(ExtractedRecord $draft): Person
    {
        $payload = $draft->payload ?? [];

        $name = $payload['name'] ?? null;
        if (! $name || ! is_string($name) || trim($name) === '') {
            throw new DraftConfirmationException(
                'This person is missing a name in their payload.'
            );
        }

        // If an existing person matches by name, return them and skip
        // creation. The user can manually merge richer details later
        // by editing the existing record.
        $existing = $this->personResolver->findByName($name);
        if ($existing) {
            return $existing;
        }

        $attributes = $this->filterFillable($payload, Person::class);

        // Resolve current organization if a name was provided. Absent
        // is fine — the FK is nullable.
        if (! empty($payload['current_organization_name'])) {
            $orgName = $payload['current_organization_name'];
            $organization = $this->findOrganizationByName($orgName);
            if (! $organization) {
                throw new DraftConfirmationException(
                    "Can't confirm this person — their current organization " .
                    "\"{$orgName}\" isn't in your catalog yet. Confirm the " .
                    "organization draft first, or remove the organization " .
                    "reference from this person's payload."
                );
            }
            $attributes['current_organization_id'] = $organization->id;
        }

        return Person::create($attributes);
    }

    /**
     * Attach nested tags from a draft payload to a freshly-created
     * parent entity. The payload's `tags` field is an array of
     * `{name: string, category?: string}` objects — the AI emits the
     * name as it appears in the document and the category from the
     * closed Tag::CATEGORIES enum.
     *
     * Each entry is resolved against existing tags/aliases or creates
     * a new tag — the actual lookup logic lives in TagResolver. The
     * category is applied only when a new tag is created; existing
     * tags keep their stored category (user curation wins). Invalid
     * categories are dropped at the resolver, not here.
     *
     * No-op if the payload has no tags field or it's empty.
     *
     * Per-name rejection and rename decisions made during AI review
     * are not consulted here. Those decisions live as their own
     * extracted_records rows (record_type = `tag`) and are applied
     * at their own confirmation step in the wizard flow — see
     * milestone 4.6 in docs/06-planned-features.md.
     */
    private function attachNestedTags(Model $parent, ExtractedRecord $draft): void
    {
        $payload = $draft->payload ?? [];
        $tagEntries = $payload['tags'] ?? null;
        if (! is_array($tagEntries) || empty($tagEntries)) {
            return;
        }

        $tagIds = [];
        foreach ($tagEntries as $entry) {
            if (! is_array($entry) || empty($entry['name']) || ! is_string($entry['name'])) {
                continue;
            }
            if (trim($entry['name']) === '') {
                continue;
            }

            $category = isset($entry['category']) && is_string($entry['category']) && trim($entry['category']) !== ''
                ? trim($entry['category'])
                : null;

            $tag = $this->tagResolver->resolve($entry['name'], $category);
            $tagIds[] = $tag->id;
        }

        // syncWithoutDetaching deduplicates by key — if the AI emitted
        // the same tag twice, or two emissions resolved to the same
        // existing tag, we still get one pivot row.
        if (! empty($tagIds)) {
            $parent->tags()->syncWithoutDetaching(array_unique($tagIds));
        }
    }

    /**
     * Attach nested collaborators from a draft payload to a
     * freshly-created parent entity. Each collaborator is
     * `{name: string, role?: string}`. Person names are resolved via
     * PersonResolver — find an existing person by case-insensitive
     * name, or create a new one. The role lands in the pivot column
     * specified by $roleColumn (varies per parent type: role_on_position,
     * role_on_project, role_on_accomplishment).
     *
     * Empty role strings normalize to null at the pivot — matches the
     * convention from PersonRules::buildCollaboratorSyncData used by
     * the manual form picker, so AI-extracted data lands in the same
     * shape as user-entered data.
     *
     * Per-name rejection and rename decisions made during AI review
     * are not consulted here. Those decisions live as their own
     * extracted_records rows (record_type = `person`) and are applied
     * at their own confirmation step in the wizard flow — see
     * milestone 4.6 in docs/06-planned-features.md.
     */
    private function attachNestedCollaborators(Model $parent, ExtractedRecord $draft, string $roleColumn): void
    {
        $payload = $draft->payload ?? [];
        $collaborators = $payload['collaborators'] ?? null;
        if (! is_array($collaborators) || empty($collaborators)) {
            return;
        }

        $syncData = [];
        foreach ($collaborators as $collaborator) {
            if (! is_array($collaborator) || empty($collaborator['name'])) {
                continue;
            }

            $person = $this->personResolver->resolve($collaborator['name']);
            $role = isset($collaborator['role']) && is_string($collaborator['role'])
                ? trim($collaborator['role'])
                : '';
            $syncData[$person->id] = [
                $roleColumn => $role !== '' ? $role : null,
            ];
        }

        if (! empty($syncData)) {
            $parent->collaborators()->syncWithoutDetaching($syncData);
        }
    }

    /**
     * Attach nested links from a draft payload to a freshly-created
     * parent entity. The payload's `links` field is an array of
     * `{url, type?, title?, description?, is_personal_appearance?, date?}`
     * objects. The parent is the entity the link is nested under —
     * Laravel's morphMany handles the (linkable_type, linkable_id)
     * columns automatically when the row is created via the relation.
     *
     * The `type` field is validated against Link::TYPES; an unrecognized
     * value defaults to "other" since the column is non-nullable. A
     * missing or empty `type` also defaults to "other". This matches
     * the defense-in-depth principle used elsewhere in this class —
     * we preserve the mention rather than fail the confirmation.
     *
     * Entries without a usable url are skipped. No-op if the payload
     * has no links field or it's empty.
     */
    private function attachNestedLinks(Model $parent, ExtractedRecord $draft): void
    {
        $payload = $draft->payload ?? [];
        $linkEntries = $payload['links'] ?? null;
        if (! is_array($linkEntries) || empty($linkEntries)) {
            return;
        }

        foreach ($linkEntries as $entry) {
            if (! is_array($entry) || empty($entry['url']) || ! is_string($entry['url'])) {
                continue;
            }
            if (trim($entry['url']) === '') {
                continue;
            }

            $type = isset($entry['type']) && is_string($entry['type']) && in_array($entry['type'], Link::TYPES, true)
                ? $entry['type']
                : 'other';

            $attributes = [
                'url' => trim($entry['url']),
                'type' => $type,
            ];

            // Optional descriptive fields — pass through when present.
            foreach (['title', 'description', 'date'] as $field) {
                if (isset($entry[$field]) && is_string($entry[$field]) && trim($entry[$field]) !== '') {
                    $attributes[$field] = $entry[$field];
                }
            }

            if (isset($entry['is_personal_appearance']) && is_bool($entry['is_personal_appearance'])) {
                $attributes['is_personal_appearance'] = $entry['is_personal_appearance'];
            }

            $parent->links()->create($attributes);
        }
    }

    /**
     * Filter a payload to only the keys that are fillable on the
     * target model. This drops AI-generated fields that don't map
     * to columns (like organization_name on a position, where we
     * use the resolved organization_id instead) and any extraneous
     * fields the AI added beyond our schema.
     */
    private function filterFillable(array $payload, string $modelClass): array
    {
        /** @var Model $instance */
        $instance = new $modelClass;
        $fillable = $instance->getFillable();

        return array_intersect_key($payload, array_flip($fillable));
    }

    private function findOrganizationByName(string $name): ?Organization
    {
        return Organization::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
            ->first();
    }

    private function findPositionByRef(Organization $organization, string $title): ?Position
    {
        return Position::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('LOWER(title) = ?', [strtolower(trim($title))])
            ->first();
    }

    private function findProjectByName(Organization $organization, string $name): ?Project
    {
        return Project::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
            ->first();
    }
}