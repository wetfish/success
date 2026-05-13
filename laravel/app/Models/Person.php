<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person — a manager, collaborator, mentor, recruiter, or other
 * individual relevant to the user's career.
 *
 * Modeled once and referenced from multiple places via pivot tables
 * with role columns. A person can be attached to positions
 * (position_collaborators, role_on_position — e.g. "Manager"),
 * projects (project_collaborators, role_on_project), and
 * accomplishments (accomplishment_collaborators, role_on_accomplishment).
 * The three pivots have identical shape: one parent FK, one person FK,
 * one role free-text column. This keeps the people-attachment pattern
 * uniform across the schema.
 *
 * For MVP, a person is associated with a single current organization.
 * A person_organization_history table will be added later to track
 * career changes over time (deferred per the planning doc).
 */
#[Fillable([
    'name',
    'current_title',
    'current_organization_id',
    'email',
    'relationship_type',
    'user_notes',
])]
class Person extends Model
{
    use SoftDeletes;

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'position_collaborators')
            ->withPivot('role_on_position')
            ->withTimestamps();
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_collaborators')
            ->withPivot('role_on_project')
            ->withTimestamps();
    }

    public function accomplishments(): BelongsToMany
    {
        return $this->belongsToMany(Accomplishment::class, 'accomplishment_collaborators')
            ->withPivot('role_on_accomplishment')
            ->withTimestamps();
    }

    public function links(): MorphMany
    {
        return $this->morphMany(Link::class, 'linkable');
    }
}