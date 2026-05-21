<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Entry point to the resume generation flow. A job listing captures
 * a role the user is applying to, parented by an organization
 * (typically type `prospect`, though applying to a former employer
 * is valid).
 *
 * The raw listing text is preserved verbatim in `body`. Optional
 * `structured_data` holds AI-extracted fields as JSON — the shape
 * evolves during dogfooding.
 */
#[Fillable([
    'organization_id',
    'role_title',
    'body',
    'structured_data',
    'source_url',
    'location',
    'compensation_range',
    'date_posted',
    'status',
])]
class JobListing extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'structured_data' => 'array',
            'date_posted' => 'date',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function resumeDrafts(): HasMany
    {
        return $this->hasMany(ResumeDraft::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(JobListingRequirement::class);
    }
}