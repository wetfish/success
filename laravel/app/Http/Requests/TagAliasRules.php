<?php

namespace App\Http\Requests;

use App\Models\Tag;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules and input normalization for TagAlias form
 * requests. Only StoreTagAliasRequest delegates here today — aliases
 * are immutable once created (no edit form, no UpdateTagAliasRequest).
 * The reason: aliases have only two fields (tag_id, set once at
 * creation, and the alias text itself). Editing the text in place
 * is semantically equivalent to destroy + recreate, so we keep the
 * mental model simple by enforcing that explicitly. The UI offers
 * inline delete on each alias chip, and an "Add alias" form for new
 * ones — no edit affordance anywhere.
 *
 * Tag aliases have one cross-table invariant the form layer enforces:
 * an alias text must not collide with any existing canonical tag
 * name. Mirrors the rule on TagRules where canonical names can't
 * collide with aliases — both directions are checked at the form
 * layer for friendly errors, and `TagAlias::validateInvariants()`
 * remains the model-level safety net.
 *
 * Uniqueness across aliases is also enforced: the same alias text
 * can't exist twice (regardless of which tag it points to). This is
 * a stricter rule than "unique per tag" because alias-resolution
 * lookups happen by alias text alone — duplicates would create
 * ambiguity at lookup time.
 */
class TagAliasRules
{
    public static function rules(): array
    {
        return [
            'alias' => [
                'required',
                'string',
                'max:255',
                Rule::unique('tag_aliases', 'alias'),
                // Cross-table invariant: alias text must not collide
                // with any existing canonical tag name. The closure
                // surfaces a specific error message rather than the
                // generic "already taken."
                static function (string $attribute, mixed $value, callable $fail) {
                    if (! is_string($value) || $value === '') {
                        return;
                    }
                    $collidingTag = Tag::where('name', $value)->first();
                    if ($collidingTag) {
                        $fail("The alias \"{$value}\" is already used as the canonical name of an existing tag.");
                    }
                },
            ],
        ];
    }

    /**
     * Normalize raw form input. Trims the alias text. Empty strings
     * remain empty so the required rule catches them rather than
     * being coerced to null (since the column is non-nullable, that
     * would produce a less helpful error).
     */
    public static function normalize(array $input): array
    {
        $cleaned = [];

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
            }
            $cleaned[$key] = $value;
        }

        return $cleaned;
    }
}