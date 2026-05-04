<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single funding round for an organization.
 *
 * Note on `amount_raised`: stored as integer cents (unsigned bigint)
 * per the project's money convention. Reads return raw cents; writes
 * accept raw cents. Use App\Support\Money::format() and ::parse() at
 * the application boundary to convert to and from human-readable
 * dollar strings. See docs/05-ai-development-notes.md for the full
 * convention.
 */
#[Fillable([
    'organization_id',
    'round_name',
    'round_date',
    'amount_raised',
    'currency',
    'lead_investor',
    'notes',
])]
class FundingRound extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'round_date' => 'date',
            'amount_raised' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}