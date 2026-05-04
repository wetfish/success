<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;

/**
 * Tags are flat reference data shared across the application.
 *
 * Per the schema design principles, tags do not use soft deletes —
 * they are infrastructure that supports other entities, and the cost
 * of accidental hard-deletion is low (tag definitions are easily recreated).
 */
#[Fillable(['name', 'category', 'description'])]
class Tag extends Model
{
    public const CATEGORIES = [
        'language',
        'framework',
        'tool',
        'protocol',
        'domain',
        'methodology',
        'vendor',
        'hardware',
        'concept',
    ];

    protected static function booted(): void
    {
        static::saving(function (Tag $tag) {
            $tag->validateInvariants();
        });
    }

    /**
     * Enforce the schema's cross-table invariant: a canonical tag name
     * must not collide with any existing alias (and vice versa, enforced
     * on the TagAlias side). Without this check, the alias-resolution
     * logic could ambiguously map the same string to two different tags.
     *
     * @throws InvalidArgumentException
     */
    public function validateInvariants(): void
    {
        if ($this->name === null) {
            return;
        }

        if (TagAlias::where('alias', $this->name)->exists()) {
            throw new InvalidArgumentException(
                "Cannot create tag with name '{$this->name}' — it conflicts with an existing alias."
            );
        }
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TagAlias::class);
    }

    public function organizations(): MorphToMany
    {
        return $this->morphedByMany(Organization::class, 'taggable');
    }

    public function projects(): MorphToMany
    {
        return $this->morphedByMany(Project::class, 'taggable');
    }

    public function accomplishments(): MorphToMany
    {
        return $this->morphedByMany(Accomplishment::class, 'taggable');
    }

    public function positions(): MorphToMany
    {
        return $this->morphedByMany(Position::class, 'taggable');
    }

    public function sourceDocuments(): MorphToMany
    {
        return $this->morphedByMany(SourceDocument::class, 'taggable');
    }
}