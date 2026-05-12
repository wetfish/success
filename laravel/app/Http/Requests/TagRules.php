<?php

namespace App\Http\Requests;

use App\Models\Tag;
use App\Models\TagAlias;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules and input normalization for Tag form
 * requests. Both StoreTagRequest and UpdateTagRequest delegate
 * here, so create and edit forms validate identically.
 *
 * Tags have one cross-table invariant the form layer enforces: a
 * canonical tag name must not collide with any existing alias. This
 * is checked via a custom rule rather than `Rule::unique` so the
 * error message can be specific ("conflicts with an existing alias
 * of <tag>") rather than the generic "already taken."
 *
 * The model layer's `validateInvariants()` is the safety net for
 * direct programmatic creation.
 *
 * Category labels live here too (not just in Tag::CATEGORIES) so the
 * UI can render "Programming language" instead of "language" without
 * each view recomputing the label. Other Rules classes (e.g.
 * OrganizationRules) just use ucfirst-style transforms in views;
 * tags deserve explicit labels because category surfaces in three
 * places (index grouping, edit form select, picker dropdown rows)
 * and we want consistency.
 */
class TagRules
{
    public const CATEGORIES = Tag::CATEGORIES;

    public const CATEGORY_LABELS = [
        'language' => 'Programming language',
        'framework' => 'Framework',
        'tool' => 'Tool',
        'protocol' => 'Protocol',
        'domain' => 'Domain',
        'methodology' => 'Methodology',
        'vendor' => 'Vendor',
        'hardware' => 'Hardware',
        'concept' => 'Concept',
    ];

    /**
     * @param  int|null  $ignoreId  When updating, the existing tag's
     *                              ID is passed so the unique check
     *                              skips itself (otherwise updating
     *                              a tag without changing its name
     *                              would fail).
     */
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('tags', 'name')->ignore($ignoreId),
                // Cross-table invariant: name must not collide with
                // any existing alias. The closure rule does this
                // separately so the error message can name the
                // colliding alias's parent tag, which a unique-style
                // rule against the aliases table couldn't surface.
                static function (string $attribute, mixed $value, callable $fail) {
                    if (! is_string($value) || $value === '') {
                        return;
                    }
                    $alias = TagAlias::with('tag')->where('alias', $value)->first();
                    if ($alias) {
                        $tagName = $alias->tag?->name ?? 'an existing tag';
                        $fail("The name \"{$value}\" is already used as an alias of \"{$tagName}\".");
                    }
                },
            ],
            'category' => ['nullable', 'string', Rule::in(self::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Validation rules for the tag picker's `tag_ids[]` array input.
     * Spread into a parent entity's Rules class via `+ TagRules::
     * syncRules()` (the union operator preserves keys without
     * overwriting existing ones — though `tag_ids` and `tag_ids.*`
     * shouldn't collide with anything else anyway).
     *
     * Each ID is validated as an integer that exists in the tags
     * table. A submitted ID for a tag that's since been deleted will
     * fail with an exists rule violation, which is the right
     * behavior — the form should show the user what happened rather
     * than silently dropping the stale ID.
     */
    public static function syncRules(): array
    {
        return [
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
        ];
    }

    /**
     * Normalize raw form input. Trims strings, converts empty strings
     * to null on nullable fields. Tag name is trimmed but otherwise
     * preserved exactly — case and punctuation are meaningful for
     * canonical form ("C++" is a different tag from "Cpp").
     */
    public static function normalize(array $input): array
    {
        $cleaned = [];

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            }
            $cleaned[$key] = $value;
        }

        return $cleaned;
    }
}