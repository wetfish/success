<?php

namespace App\Services\Drafts;

use App\Models\Accomplishment;
use App\Models\ExtractedRecord;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Converts a pending ExtractedRecord into a real catalog record
 * (Organization, Position, Project, or Accomplishment).
 *
 * The draft's payload is a flat array of field values produced by
 * the AI at extraction time. Parent references — organization,
 * position, project — are stored as name strings in the payload
 * because at extraction time the parent doesn't exist as a database
 * row yet. Confirmation resolves those strings to foreign-key IDs
 * by looking up exact-name (case-insensitive) matches in the
 * existing catalog.
 *
 * If a parent can't be resolved, throws DraftConfirmationException
 * with a user-facing message. The controller catches this and
 * surfaces it as a flash message; no rows are created.
 *
 * The whole confirmation (real record creation + draft status
 * update) runs in a transaction so a partial state is impossible.
 *
 * Duplicate detection — "this org draft probably matches an existing
 * Lightning Labs Inc record, do you want to merge?" — is NOT in
 * scope here. That's slice 4.5. This service does exact-name lookups
 * only; if you want fuzzy matching, build on top of this.
 */
class DraftConfirmer
{
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
     * Organization confirmation is the simplest case — no parent
     * references to resolve. Map fillable payload fields to the
     * model and create.
     */
    private function confirmOrganization(ExtractedRecord $draft): Organization
    {
        return Organization::create(
            $this->filterFillable($draft->payload ?? [], Organization::class)
        );
    }

    /**
     * Position requires organization_id, resolved from the
     * organization_name field in the payload.
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

        return Position::create($attributes);
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

        return Project::create($attributes);
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

        return Accomplishment::create($attributes);
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