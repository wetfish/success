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
     * Validation rules for the person picker's `collaborators[]` array
     * input. Spread into a parent entity's Rules class via
     * `+ PersonRules::collaboratorSyncRules()`. The union operator
     * preserves keys; `collaborators` and `collaborators.*` shouldn't
     * collide with anything else on the parent entity.
     *
     * The picker submits each chip as two fields:
     *   collaborators[i][person_id]  — required, must exist in people
     *   collaborators[i][role]       — optional free text, max 255
     *
     * The index i can be sparse (chips that have been removed leave
     * gaps in the array). Laravel's validator handles sparse arrays
     * via the `collaborators.*` wildcard.
     */
    public static function collaboratorSyncRules(): array
    {
        return [
            'collaborators' => ['nullable', 'array'],
            'collaborators.*.person_id' => ['required', 'integer', 'exists:people,id'],
            'collaborators.*.role' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Transform the raw `collaborators[]` form input into the array
     * shape Eloquent's `sync()` expects: `[person_id => ['role_column'
     * => 'role text'], ...]`. The role column name varies per parent
     * entity (role_on_position, role_on_project, role_on_accomplishment),
     * so the caller passes it in.
     *
     * Empty role strings normalize to null at this layer so the DB
     * stores a consistent "no role" sentinel — downstream queries
     * don't have to handle both '' and null.
     *
     * Returns an empty array when no collaborators are submitted,
     * which `sync()` treats as "detach all" — matching the form's
     * "what you see is what's saved" contract.
     */
    public static function buildCollaboratorSyncData(?array $collaborators, string $roleColumn): array
    {
        if (! $collaborators) {
            return [];
        }

        $result = [];
        foreach ($collaborators as $row) {
            if (! is_array($row) || empty($row['person_id'])) {
                continue;
            }
            $personId = (int) $row['person_id'];
            $role = isset($row['role']) && is_string($row['role']) ? trim($row['role']) : '';
            $result[$personId] = [
                $roleColumn => $role !== '' ? $role : null,
            ];
        }

        return $result;
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