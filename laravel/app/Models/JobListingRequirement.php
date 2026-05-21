<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A structured requirement extracted by the AI from a job listing.
 * Requirements are properties of the listing, not of any specific
 * draft — multiple resume drafts share the same requirements.
 *
 * The `section` column captures which part of the listing this came
 * from: required, preferred, or responsibility. The `category`
 * column classifies the type (technical_skill, framework, tool,
 * domain_knowledge, etc.).
 *
 * Resume selections reference requirements via FK, grouping the
 * review page by requirement rather than by catalog entity type.
 */
#[Fillable([
    'job_listing_id',
    'category',
    'title',
    'description',
    'section',
    'display_order',
])]
class JobListingRequirement extends Model
{
    public function jobListing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class);
    }

    public function resumeSelections(): HasMany
    {
        return $this->hasMany(ResumeSelection::class);
    }
}