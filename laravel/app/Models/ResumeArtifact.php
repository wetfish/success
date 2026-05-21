<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An immutable formatted output file — a point-in-time snapshot of
 * a rendered resume. A draft can have multiple artifacts: different
 * formats (.docx, .pdf), or re-generations after further editing.
 *
 * The `file_path` is relative to Laravel's storage directory
 * (storage/app/). Files are served via a controller download route
 * rather than publicly accessible URLs.
 */
#[Fillable([
    'resume_draft_id',
    'file_path',
    'file_format',
    'file_size_bytes',
])]
class ResumeArtifact extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
        ];
    }

    public function resumeDraft(): BelongsTo
    {
        return $this->belongsTo(ResumeDraft::class);
    }
}