<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * TODO: reports_to_person_id is fillable and the relationship works,
 * but the field is not yet exposed in the Position form UI pending the
 * Person UI slice. When Person CRUD lands, add a Person picker to the
 * form template — no model changes needed at that point.
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
    'reports_to_person_id',
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

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'reports_to_person_id');
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