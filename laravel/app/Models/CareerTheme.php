<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A career theme is a narrative thread the user identifies across
 * their own career — "distributed systems with a privacy bent,"
 * "alternated between deep technical work and team leadership."
 *
 * Themes don't emerge automatically from project data. They are
 * creative acts of self-framing that the user authors, and the AI
 * uses them as the spine of tailored output: pick the relevant
 * theme(s) for a given job, then pull the best evidence under each.
 */
#[Fillable([
    'name',
    'description',
    'display_order',
])]
class CareerTheme extends Model
{
    use SoftDeletes;

    /**
     * Default attribute values applied to fresh model instances.
     * Mirrors the database-level default on this column so that the
     * in-memory model behaves consistently with a loaded one.
     */
    protected $attributes = [
        'display_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'career_theme_projects');
    }

    public function accomplishments(): BelongsToMany
    {
        return $this->belongsToMany(Accomplishment::class, 'career_theme_accomplishments');
    }
}