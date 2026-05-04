<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aliases let multiple input strings ("Postgres", "PostgreSQL", "postgres")
 * resolve to the same canonical tag. Used during tag matching from
 * job listings, AI extraction, and user input.
 */
#[Fillable(['tag_id', 'alias'])]
class TagAlias extends Model
{
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}