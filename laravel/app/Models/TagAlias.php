<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Aliases let multiple input strings ("Postgres", "PostgreSQL", "postgres")
 * resolve to the same canonical tag. Used during tag matching from
 * job listings, AI extraction, and user input.
 */
#[Fillable(['tag_id', 'alias'])]
class TagAlias extends Model
{
    protected static function booted(): void
    {
        static::saving(function (TagAlias $alias) {
            $alias->validateInvariants();
        });
    }

    /**
     * Enforce the schema's cross-table invariant: an alias must not
     * collide with any existing canonical tag name. Without this check,
     * the alias-resolution logic could ambiguously map the same string
     * to two different tags.
     *
     * @throws InvalidArgumentException
     */
    public function validateInvariants(): void
    {
        if ($this->alias === null) {
            return;
        }

        if (Tag::where('name', $this->alias)->exists()) {
            throw new InvalidArgumentException(
                "Cannot create alias '{$this->alias}' — it conflicts with an existing canonical tag name."
            );
        }
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}