<?php

namespace App\Http\Requests;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules and input normalization for Organization
 * form requests. Both StoreOrganizationRequest and UpdateOrganizationRequest
 * delegate here, so create and edit forms validate identically.
 *
 * Type and status values come from the OrganizationType and
 * OrganizationStatus enums respectively. See docs/07-conventions.md
 * for the enum-as-single-source-of-truth pattern.
 */
class OrganizationRules
{
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::enum(OrganizationType::class)],
            'website' => ['nullable', 'url', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'headquarters' => ['nullable', 'string', 'max:255'],
            'founded_year' => ['nullable', 'integer', 'min:1800', 'max:' . (date('Y') + 1)],
            'size_estimate' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::enum(OrganizationStatus::class)],
            'user_notes' => ['nullable', 'string', 'max:10000'],
        ] + TagRules::syncRules();
    }

    /**
     * Normalize raw form input. Strips thousands separators from
     * founded_year, trims strings, converts empty strings to null
     * on nullable fields so validation treats them as absent.
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

        // Strip commas from founded_year before integer validation
        if (isset($cleaned['founded_year']) && is_string($cleaned['founded_year'])) {
            $cleaned['founded_year'] = str_replace([',', ' '], '', $cleaned['founded_year']);
        }

        return $cleaned;
    }
}