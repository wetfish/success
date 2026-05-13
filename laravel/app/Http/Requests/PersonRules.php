<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Shared validation rules and input normalization for Person form
 * requests. Both StorePersonRequest and UpdatePersonRequest delegate
 * here, so create and edit forms validate identically.
 *
 * The schema permits every field except `name` to be null — this
 * matches the quick-add flow from the person picker (a name is all
 * you have when capturing a collaborator mid-form), as well as the
 * incremental enrichment pattern where users add detail over time.
 *
 * `current_organization_id` validates against organizations.id with
 * the `exists` rule. The DB FK uses set-null-on-delete, but we
 * still want the form layer to catch stale or fabricated IDs at
 * submission time with a friendly error.
 */
class PersonRules
{
    public const RELATIONSHIP_TYPES = [
        'manager',
        'report',
        'peer',
        'mentor',
        'mentee',
        'client',
        'vendor',
        'recruiter',
        'other',
    ];

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'current_title' => ['nullable', 'string', 'max:255'],
            'current_organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'email' => ['nullable', 'email', 'max:255'],
            'relationship_type' => ['nullable', 'string', Rule::in(self::RELATIONSHIP_TYPES)],
            'user_notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * Normalize raw form input. Trims strings, converts empty strings
     * to null on nullable fields, lowercases email for storage
     * consistency (so the picker's autocomplete matches across casing).
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

        // Email casing matters for display but not for identity.
        // Lowercase at the form layer so duplicate detection (eventual
        // feature) and search (current feature) operate on a normalized
        // value without each call site doing it.
        if (isset($cleaned['email']) && is_string($cleaned['email'])) {
            $cleaned['email'] = strtolower($cleaned['email']);
        }

        return $cleaned;
    }
}