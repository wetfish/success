<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records a single AI API call: the provider, the model used, the
 * operation type, the tokens consumed, and the cost in cents.
 *
 * Cost is stored in cents per the Money helper convention — use
 * App\Support\Money to format for display.
 */
#[Fillable([
    'provider',
    'model',
    'operation',
    'source_document_id',
    'input_tokens',
    'output_tokens',
    'cost_cents',
    'success',
    'error_message',
])]
class AiUsageEvent extends Model
{
    public const OPERATIONS = [
        'extract_text',
        'extract_pdf',
        'synthesize',
        'count_tokens',
        'health_check',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_cents' => 'integer',
            'success' => 'boolean',
        ];
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class);
    }
}