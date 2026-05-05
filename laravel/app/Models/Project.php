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
use InvalidArgumentException;

/**
 * A project is the unit of work within a position, or for personal
 * projects, within an organization without a position. Projects can
 * nest — a long-running product workstream is often a parent project
 * containing discrete sub-initiatives.
 *
 * The schema captures problem, constraints, approach, outcome, and
 * rationale as distinct fields. This is the "story shape" pattern
 * from the design philosophy — projects are not just a description
 * of work, they are sequences of decisions made under constraint.
 *
 * Date precision (day / month / quarter / year) is a UI hint and
 * tells the AI how confident to be when generating resume text.
 * Internally dates are stored as real date values regardless.
 */
#[Fillable([
    'organization_id',
    'position_id',
    'parent_project_id',
    'name',
    'public_name',
    'description',
    'problem',
    'constraints',
    'approach',
    'outcome',
    'rationale',
    'start_date',
    'end_date',
    'date_precision',
    'visibility',
    'status',
    'contribution_level',
    'contribution_type',
    'team_size',
    'user_notes',
])]
class Project extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'team_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            $project->validateInvariants();
        });
    }

    /**
     * Enforces the schema's structural constraint on project nesting:
     * a sub-project's parent must belong to the same organization.
     * Cross-org parenting would represent a data model error.
     *
     * Note: both sides of the comparison are cast to int before
     * checking equality. The DB-fetched value comes back as int via
     * Eloquent, but `$this->organization_id` may be a string when set
     * from form input that hasn't been cast yet (mass assignment from
     * POST data flows through saving() before casts apply consistently).
     * Strict comparison without normalization would treat `1 !== "1"`
     * as a real mismatch and falsely reject valid sub-projects.
     *
     * @throws InvalidArgumentException
     */
    public function validateInvariants(): void
    {
        if ($this->parent_project_id === null) {
            return;
        }

        $parentOrgId = static::where('id', $this->parent_project_id)
            ->value('organization_id');

        if ($parentOrgId !== null && (int) $parentOrgId !== (int) $this->organization_id) {
            throw new InvalidArgumentException(
                'A sub-project must belong to the same organization as its parent project.'
            );
        }
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function parentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_project_id');
    }

    public function childProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'parent_project_id');
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

    public function sourceDocuments(): BelongsToMany
    {
        return $this->belongsToMany(SourceDocument::class, 'project_source_documents');
    }

    public function careerThemes(): BelongsToMany
    {
        return $this->belongsToMany(CareerTheme::class, 'career_theme_projects');
    }
}