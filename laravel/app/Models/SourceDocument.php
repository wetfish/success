<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A source document holds raw, unstructured notes — interview prep,
 * performance reviews, brag docs, journals, meeting notes — that get
 * extracted into structured accomplishments and projects.
 *
 * Source documents are the audit trail for AI-extracted data. When
 * a structured record is created via the extraction pipeline, the
 * relationship to its originating source document is preserved so
 * the original voice and texture isn't lost, and so re-extraction
 * is possible if the schema evolves.
 */
#[Fillable([
    'title',
    'kind',
    'body',
    'context_date',
    'context_notes',
])]
class SourceDocument extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'context_date' => 'date',
        ];
    }

    public function accomplishments(): BelongsToMany
    {
        return $this->belongsToMany(Accomplishment::class, 'accomplishment_source_documents');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_source_documents');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}