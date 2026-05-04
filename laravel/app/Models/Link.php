<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * A polymorphic link — URLs and external references attached to
 * organizations, projects, accomplishments, positions, and people.
 *
 * The `is_personal_appearance` flag distinguishes signature evidence
 * (a media appearance, conference talk, podcast where the user is
 * featured) from supporting evidence (documentation, repos, demos).
 * This affects how the AI weights links during resume generation.
 *
 * The `type` column accepts a fixed list of values, validated below.
 * The `internal_doc` type is special — it represents documents that
 * exist but aren't shareable (NDA'd internal docs, confidential
 * research, etc.) and so may have a null url.
 */
#[Fillable([
    'linkable_type',
    'linkable_id',
    'type',
    'url',
    'title',
    'description',
    'is_personal_appearance',
    'date',
])]
class Link extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'website',
        'twitter',
        'github',
        'linkedin',
        'blog',
        'slack',
        'careers',
        'repo',
        'documentation',
        'live_demo',
        'media_appearance',
        'talk',
        'blog_post',
        'case_study',
        'internal_doc',
        'other',
    ];

    /**
     * Default attribute values applied to fresh model instances.
     * Mirrors the database-level default on this column so that the
     * in-memory model behaves consistently with a loaded one.
     */
    protected $attributes = [
        'is_personal_appearance' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_personal_appearance' => 'boolean',
            'date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Link $link) {
            $link->validateInvariants();
        });
    }

    /**
     * Enforces the schema's type-specific rules:
     *   - `internal_doc` type may have null url, but must have a title
     *   - all other types require a url
     *
     * @throws InvalidArgumentException
     */
    public function validateInvariants(): void
    {
        if ($this->type === 'internal_doc') {
            if (empty($this->title)) {
                throw new InvalidArgumentException(
                    'Links of type "internal_doc" require a title.'
                );
            }
        } else {
            if (empty($this->url)) {
                throw new InvalidArgumentException(
                    "Links of type \"{$this->type}\" require a url."
                );
            }
        }
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}