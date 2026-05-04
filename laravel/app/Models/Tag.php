<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Tags are flat reference data shared across the application.
 *
 * Per the schema design principles, tags do not use soft deletes —
 * they are infrastructure that supports other entities, and the cost
 * of accidental hard-deletion is low (tag definitions are easily recreated).
 *
 * Additional taggable target relationships (projects, accomplishments,
 * positions, source_documents) will be added to this model as those
 * entity models are built in subsequent slices.
 */
#[Fillable(['name', 'category', 'description'])]
class Tag extends Model
{
    public function aliases(): HasMany
    {
        return $this->hasMany(TagAlias::class);
    }

    public function organizations(): MorphToMany
    {
        return $this->morphedByMany(Organization::class, 'taggable');
    }
}