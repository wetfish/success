<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 *
 * Sources can be either pasted text (stored in `body`) or an uploaded
 * file (PDF, .txt, or .md, stored at `file_path` with `file_type`
 * indicating the format). PDFs are sent directly to Claude as base64;
 * text and markdown files have their contents read into `body` at
 * upload time so the body column always holds the textual source.
 *
 * Extraction status is intentionally not stored as a column. It is
 * derived from related tables — see isPending() and friends below.
 */
#[Fillable([
    'title',
    'kind',
    'file_path',
    'file_type',
    'body',
    'context_date',
    'context_notes',
    'review_decisions',
])]
class SourceDocument extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'context_date' => 'date',
            'review_decisions' => 'array',
        ];
    }

    /**
     * Tag names the user explicitly rejected during review. The
     * DraftConfirmer skips these when materializing nested tag
     * attachments from extracted record payloads. Compared
     * case-insensitively against AI-emitted tag names.
     */
    public function rejectedTags(): array
    {
        return ($this->review_decisions ?? [])['rejected_tags'] ?? [];
    }

    /**
     * AI-emitted-name → user-corrected-name map for tags. When the AI
     * emitted "Postgres 14" and the user wants the catalog to use
     * "Postgres" instead, the rename is recorded here. The confirmer
     * applies the rename before resolving — the corrected name then
     * goes through normal name-or-alias lookup.
     */
    public function renamedTags(): array
    {
        return ($this->review_decisions ?? [])['renamed_tags'] ?? [];
    }

    /**
     * Collaborator names the user rejected during review. The
     * DraftConfirmer skips these when materializing collaborator
     * attachments from extracted record payloads.
     */
    public function rejectedCollaborators(): array
    {
        return ($this->review_decisions ?? [])['rejected_collaborators'] ?? [];
    }

    /**
     * AI-emitted-name → user-corrected-name map for collaborators.
     * Equivalent to renamedTags but for people. Used when the AI
     * emitted "Sarah Chen" but the user knows the canonical name in
     * the catalog is "Sarah K Chen" — the rename gets the right
     * existing record attached.
     */
    public function renamedCollaborators(): array
    {
        return ($this->review_decisions ?? [])['renamed_collaborators'] ?? [];
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

    public function extractedRecords(): HasMany
    {
        return $this->hasMany(ExtractedRecord::class);
    }

    public function aiUsageEvents(): HasMany
    {
        return $this->hasMany(AiUsageEvent::class);
    }

    public function isPdf(): bool
    {
        return $this->file_type === 'pdf';
    }

    /**
     * Pending: no extraction has been attempted yet — there are no
     * extracted_records and no ai_usage_events for this document.
     */
    public function isPending(): bool
    {
        return ! $this->extractedRecords()->exists()
            && ! $this->aiUsageEvents()->exists();
    }

    /**
     * Completed: at least one draft has been produced.
     */
    public function isCompleted(): bool
    {
        return $this->extractedRecords()->exists();
    }

    /**
     * Failed: an extraction was attempted but produced no drafts and
     * the most recent attempt was unsuccessful.
     */
    public function isFailed(): bool
    {
        if ($this->isCompleted()) {
            return false;
        }

        $mostRecent = $this->aiUsageEvents()
            ->whereIn('operation', ['extract_text', 'extract_pdf'])
            ->latest('id')
            ->first();

        return $mostRecent !== null && ! $mostRecent->success;
    }
}