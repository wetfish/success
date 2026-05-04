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
 * Modeled once and referenced from multiple places: positions point
 * at managers, accomplishments link to collaborators, the eventual
 * relationship-management feature tracks follow-up cadence with people.
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