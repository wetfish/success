<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Tracks which catalog entries the AI suggested for a resume and
 * whether the user chose to include each one. Polymorphic across
 * Position, Project, Accomplishment, CareerTheme, Tag, and Link.
 *
 * The `selected` boolean is the user's decision: true = include
 * in the resume, false = exclude. The AI sets all to true by
 * default; the user toggles off what they don't want.
 *
 * `ai_reasoning` holds the AI's explanation for why it suggested
 * each entry, shown in the review UI to help the user decide.
 *
 * No soft deletes — lightweight decision records like
 * accomplishment_collaborators.
 */
#[Fillable([
    'resume_draft_id',
    'selectable_type',
    'selectable_id',
    'selected',
    'ai_reasoning',
    'display_order',
])]
class ResumeSelection extends Model
{
    protected function casts(): array
    {
        return [
            'selected' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function resumeDraft(): BelongsTo
    {
        return $this->belongsTo(ResumeDraft::class);
    }

    public function selectable(): MorphTo
    {
        return $this->morphTo();
    }
}