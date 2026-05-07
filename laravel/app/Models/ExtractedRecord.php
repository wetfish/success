<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A draft record produced by AI extraction. Stays in this staging
 * table until the user confirms (becoming a real record), rejects
 * (discarded), or merges (combined with an existing record).
 */
#[Fillable([
    'source_document_id',
    'record_type',
    'payload',
    'status',
    'match_record_type',
    'match_record_id',
])]
class ExtractedRecord extends Model
{
    public const RECORD_TYPES = ['organization', 'position', 'project', 'accomplishment'];
    public const STATUSES = ['pending', 'confirmed', 'rejected', 'merged'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}