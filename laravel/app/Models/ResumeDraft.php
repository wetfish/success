<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One draft per resume generation attempt against a job listing.
 * The `status` column drives the three-step wizard flow:
 *
 *   selecting  — user reviewing AI-suggested catalog entries
 *   drafting   — selections confirmed, AI generation in progress
 *   editing    — draft generated, user reviewing/editing
 *   approved   — ready for formatting
 *   formatted  — final document generated
 *
 * Content is stored as two columns: `generated_content` (immutable
 * AI output for revert) and `user_content` (editable copy).
 */
#[Fillable([
    'job_listing_id',
    'strategy_summary_generated',
    'strategy_summary',
    'requirement_decisions',
    'generated_content',
    'user_content',
    'format_preference',
    'status',
])]
class ResumeDraft extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'status' => 'selecting',
    ];

    protected function casts(): array
    {
        return [
            'requirement_decisions' => 'array',
            'generated_content' => 'string',
            'user_content' => 'string',
        ];
    }

    public function jobListing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class);
    }

    public function selections(): HasMany
    {
        return $this->hasMany(ResumeSelection::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ResumeArtifact::class);
    }

    /**
     * Whether all selections have been decided (none left toggled
     * to the AI default without user review). For MVP, any state
     * is considered "decided" — the user can confirm at any time.
     */
    public function isSelecting(): bool
    {
        return $this->status === 'selecting';
    }

    public function isDrafting(): bool
    {
        return $this->status === 'drafting';
    }

    public function isEditing(): bool
    {
        return $this->status === 'editing';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isFormatted(): bool
    {
        return $this->status === 'formatted';
    }
}