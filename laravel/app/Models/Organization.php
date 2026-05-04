<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The top of the entity hierarchy. Covers employers, clients,
 * personal projects, open-source orgs, volunteer orgs, and
 * educational institutions — distinguished by the `type` field.
 */
#[Fillable([
    'name',
    'type',
    'website',
    'tagline',
    'description',
    'headquarters',
    'founded_year',
    'size_estimate',
    'status',
    'enriched_at',
    'user_notes',
])]
class Organization extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'enriched_at' => 'datetime',
            'founded_year' => 'integer',
        ];
    }

    public function fundingRounds(): HasMany
    {
        return $this->hasMany(FundingRound::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function people(): HasMany
    {
        return $this->hasMany(Person::class, 'current_organization_id');
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