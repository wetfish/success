<?php

namespace App\Http\Requests;

use App\Models\Link;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules and input normalization for Link form
 * requests. Both StoreLinkRequest and UpdateLinkRequest delegate
 * here, so create and edit forms validate identically.
 *
 * The interesting wrinkle for links is the type-conditional URL/title
 * rules: `internal_doc` links may have a null URL but require a title,
 * while all other types require a URL. This mirrors the model's
 * `validateInvariants()`; the form layer produces friendly errors,
 * the model is the safety net.
 *
 * `linkable_type` and `linkable_id` are only validated by
 * StoreLinkRequest — the polymorphic parent is fixed at creation
 * time and cannot be changed via update. See LinkController for
 * the form-alias-to-model-class mapping.
 */
class LinkRules
{
    public const TYPES = Link::TYPES;

    /**
     * Form-alias strings accepted by the polymorphic store endpoint.
     * The controller maps these to fully-qualified model classes
     * before resolving the parent. Kept here (rather than in the
     * controller) so the rule layer can validate the alias without
     * a controller dependency.
     */
    public const LINKABLE_ALIASES = [
        'organization',
        'project',
        'position',
        'accomplishment',
    ];

    /**
     * Display labels for link types. The shape doesn't fit the simple
     * `ucfirst(str_replace('_', ' ', $type))` treatment used elsewhere
     * because the value list includes brand names (`github` → "GitHub",
     * not "Github") and compound phrases that read better with explicit
     * casing. Used by select options on the form and by the inline
     * type label on the section partial.
     */
    public const TYPE_LABELS = [
        'website' => 'Website',
        'twitter' => 'Twitter / X',
        'github' => 'GitHub',
        'linkedin' => 'LinkedIn',
        'blog' => 'Blog',
        'slack' => 'Slack',
        'careers' => 'Careers page',
        'repo' => 'Code repository',
        'documentation' => 'Documentation',
        'live_demo' => 'Live demo',
        'media_appearance' => 'Media appearance',
        'talk' => 'Talk / presentation',
        'blog_post' => 'Blog post',
        'case_study' => 'Case study',
        'internal_doc' => 'Internal document',
        'other' => 'Other',
    ];

    /**
     * Which link types make sense for each parent type, per the schema
     * doc: "the UI is responsible for surfacing context-appropriate
     * types when adding a link." The database still accepts any value
     * from `Link::TYPES` regardless of parent — this is purely a UI
     * affordance to reduce nonsense entries (e.g., `slack` on an
     * accomplishment).
     *
     * Erring on the side of generous: when in doubt about whether a
     * type fits, include it. `internal_doc` and `other` are always
     * applicable.
     */
    public const TYPES_BY_LINKABLE = [
        'organization' => [
            'website', 'twitter', 'github', 'linkedin', 'blog', 'slack',
            'careers', 'media_appearance', 'talk', 'blog_post',
            'case_study', 'internal_doc', 'other',
        ],
        'project' => [
            'website', 'github', 'repo', 'documentation', 'live_demo',
            'twitter', 'blog_post', 'case_study', 'media_appearance',
            'talk', 'internal_doc', 'other',
        ],
        'position' => [
            'media_appearance', 'talk', 'blog_post', 'case_study',
            'internal_doc', 'other',
        ],
        'accomplishment' => [
            'media_appearance', 'talk', 'blog_post', 'case_study',
            'repo', 'live_demo', 'documentation', 'internal_doc', 'other',
        ],
    ];

    /**
     * Returns the applicable link types for a given parent alias.
     * Falls back to the full TYPES list if the alias is unknown
     * (defensive — shouldn't happen since aliases are validated).
     */
    public static function typesFor(string $linkableAlias): array
    {
        return self::TYPES_BY_LINKABLE[$linkableAlias] ?? self::TYPES;
    }

    /**
     * @param  bool  $forStore  When true, includes the polymorphic
     *                          linkable_type and linkable_id rules.
     *                          Update requests omit these because a
     *                          link cannot be reparented.
     */
    public static function rules(bool $forStore = false): array
    {
        $rules = [
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'url' => ['nullable', 'url', 'max:255', 'required_unless:type,internal_doc'],
            'title' => ['nullable', 'string', 'max:255', 'required_if:type,internal_doc'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_personal_appearance' => ['boolean'],
            'date' => ['nullable', 'date'],
        ];

        if ($forStore) {
            $rules['linkable_type'] = ['required', 'string', Rule::in(self::LINKABLE_ALIASES)];
            $rules['linkable_id'] = ['required', 'integer'];
        }

        return $rules;
    }

    public static function messages(): array
    {
        return [
            'url.required_unless' => 'A URL is required for this link type.',
            'title.required_if' => 'Internal documents need a title (since the URL may be empty).',
        ];
    }

    /**
     * Normalize raw form input. Trims strings, converts empty strings
     * to null on nullable fields, and coerces the is_personal_appearance
     * checkbox to a boolean (browsers omit unchecked boxes from the
     * request body, so we can't rely on the field being present).
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

        // Checkbox coercion: 'on', '1', true → true; missing, '', '0',
        // null, false → false. PHP's empty() treats '0' as empty, which
        // matches the behavior we want.
        $cleaned['is_personal_appearance'] = ! empty($cleaned['is_personal_appearance']);

        return $cleaned;
    }
}