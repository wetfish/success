<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Shared validation rules and input normalization for Position form
 * requests. Both StorePositionRequest and UpdatePositionRequest delegate
 * here, so create and edit forms validate identically.
 */
class PositionRules
{
    public const EMPLOYMENT_TYPES = [
        'full_time',
        'part_time',
        'contract',
        'freelance',
        'internship',
        'advisor',
        'volunteer',
        'founder',
    ];

    public const LOCATION_ARRANGEMENTS = [
        'remote',
        'hybrid',
        'on_site',
    ];

    public const REASONS_FOR_LEAVING = [
        'still_employed',
        'laid_off',
        'quit_for_opportunity',
        'quit_for_personal',
        'contract_ended',
        'company_wound_down',
        'terminated',
        'other',
    ];

    public static function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', 'string', Rule::in(self::EMPLOYMENT_TYPES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'mandate' => ['nullable', 'string', 'max:5000'],
            'location_arrangement' => ['required', 'string', Rule::in(self::LOCATION_ARRANGEMENTS)],
            'location_text' => ['nullable', 'string', 'max:255'],
            'team_name' => ['nullable', 'string', 'max:255'],
            'team_size_immediate' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'team_size_extended' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'reason_for_leaving' => ['nullable', 'string', Rule::in(self::REASONS_FOR_LEAVING)],
            'reason_for_leaving_notes' => ['nullable', 'string', 'max:5000'],
            'user_notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * Normalize raw form input. Trims strings, converts empty strings
     * to null on nullable fields, strips commas from team size numbers.
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

        foreach (['team_size_immediate', 'team_size_extended'] as $field) {
            if (isset($cleaned[$field]) && is_string($cleaned[$field])) {
                $cleaned[$field] = str_replace([',', ' '], '', $cleaned[$field]);
            }
        }

        // If reason_for_leaving is "still_employed" or empty, ensure
        // notes are also cleared — they should never persist alongside
        // a state where the user is still in the position.
        if (empty($cleaned['reason_for_leaving']) || $cleaned['reason_for_leaving'] === 'still_employed') {
            $cleaned['reason_for_leaving_notes'] = null;
        }

        return $cleaned;
    }
}