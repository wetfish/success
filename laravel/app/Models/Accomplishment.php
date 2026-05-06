<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * An accomplishment is the unit of evidence — a single discrete thing
 * the user did, ideally with a measurable impact. Accomplishments
 * belong to either a project or a position (never both, never neither)
 * and are bounded by either a single date or a period (never both).
 *
 * The structural constraints are enforced in the model layer rather
 * than at the database level, per the project's "application-level
 * constraints over database-level" principle. This gives clearer
 * error messages and is easier to evolve.
 *
 * Note on type handling: confidence and prominence are stored as
 * integers, but form-submitted values arrive as strings. The validator
 * casts to int before range comparison so `"3" >= 1` doesn't trigger
 * PHP's loose comparison rules unpredictably. Same pattern applies to
 * any future ID equality checks added here.
 */
#[Fillable([
    'project_id',
    'position_id',
    'title',
    'description',
    'impact_metric',
    'impact_value',
    'impact_unit',
    'confidence',
    'prominence',
    'context_notes',
    'date',
    'period_start',
    'period_end',
])]
class Accomplishment extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'confidence' => 'integer',
            'prominence' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Accomplishment $accomplishment) {
            $accomplishment->validateInvariants();
        });
    }

    /**
     * Enforces the schema's structural constraints in the model layer.
     *
     * @throws InvalidArgumentException
     */
    public function validateInvariants(): void
    {
        $hasProject = $this->project_id !== null;
        $hasPosition = $this->position_id !== null;

        if (! $hasProject && ! $hasPosition) {
            throw new InvalidArgumentException(
                'An accomplishment must belong to either a project or a position.'
            );
        }

        if ($hasProject && $hasPosition) {
            throw new InvalidArgumentException(
                'An accomplishment cannot belong to both a project and a position.'
            );
        }

        $hasDate = $this->date !== null;
        $hasPeriodStart = $this->period_start !== null;

        if (! $hasDate && ! $hasPeriodStart) {
            throw new InvalidArgumentException(
                'An accomplishment must have either a date or a period_start.'
            );
        }

        if ($hasDate && $hasPeriodStart) {
            throw new InvalidArgumentException(
                'An accomplishment cannot have both a date and a period_start.'
            );
        }

        if ($this->period_end !== null && $this->period_start === null) {
            throw new InvalidArgumentException(
                'period_end can only be set when period_start is set.'
            );
        }

        if ($this->period_start !== null && $this->period_end !== null) {
            if ($this->period_end->lt($this->period_start)) {
                throw new InvalidArgumentException(
                    'period_end must be on or after period_start.'
                );
            }
        }

        if ($this->confidence !== null) {
            $confidence = (int) $this->confidence;
            if ($confidence < 1 || $confidence > 5) {
                throw new InvalidArgumentException(
                    'confidence must be an integer between 1 and 5.'
                );
            }
        }

        if ($this->prominence !== null) {
            $prominence = (int) $this->prominence;
            if ($prominence < 1 || $prominence > 5) {
                throw new InvalidArgumentException(
                    'prominence must be an integer between 1 and 5.'
                );
            }
        }
    }

    public function isPointInTime(): bool
    {
        return $this->date !== null;
    }

    public function isSpan(): bool
    {
        return $this->period_start !== null;
    }

    public function isOngoing(): bool
    {
        return $this->period_start !== null && $this->period_end === null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'accomplishment_collaborators')
            ->withPivot('role_on_accomplishment')
            ->withTimestamps();
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
        return $this->belongsToMany(SourceDocument::class, 'accomplishment_source_documents');
    }

    public function careerThemes(): BelongsToMany
    {
        return $this->belongsToMany(CareerTheme::class, 'career_theme_accomplishments');
    }
}