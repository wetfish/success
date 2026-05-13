<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A position represents a specific role at an organization. Multiple
 * positions per organization is normal — promotions, internal team
 * moves, and parallel contract roles all create new position records.
 *
 * Position-level summaries are not stored as a column. They are derived
 * from underlying projects and accomplishments at render time. The
 * `mandate` field is the deliberate exception — it captures "what you
 * were hired to do," which is genuinely top-down information that
 * doesn't emerge from project data.
 *
 * Manager relationships live in the position_collaborators pivot
 * (role_on_position = "Manager") rather than a dedicated FK column.
 * This keeps the people-attachment shape consistent across positions,
 * projects, and accomplishments: one pattern, one picker, one AI
 * extraction format. See the schema doc's "People and connections"
 * section for the rationale.
 */
#[Fillable([
    'organization_id',
    'title',
    'employment_type',
    'start_date',
    'end_date',
    'location_arrangement',
    'location_text',
    'team_name',
    'team_size_immediate',
    'team_size_extended',
    'mandate',
    'reason_for_leaving',
    'reason_for_leaving_notes',
    'user_notes',
])]
class Position extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'team_size_immediate' => 'integer',
            'team_size_extended' => 'integer',
        ];
    }

    /**
     * True when this position has no end date — the user is still in
     * the role. Used to display "Current" badges on lists and to gate
     * the visibility of reason_for_leaving fields.
     */
    public function isCurrent(): bool
    {
        return $this->end_date === null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'position_collaborators')
            ->withPivot('role_on_position')
            ->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function accomplishments(): HasMany
    {
        return $this->hasMany(Accomplishment::class);
    }

    public function links(): MorphMany
    {
        return $this->morphMany(Link::class, 'linkable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}