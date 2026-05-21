<?php

namespace App\Http\Requests;

use App\Enums\JobListingStatus;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules and input normalization for JobListing
 * form requests. Both StoreJobListingRequest and
 * UpdateJobListingRequest delegate here.
 *
 * The organization_id field is populated by the org picker
 * autocomplete — either an existing organization or a newly
 * created prospect. By the time the form submits, the picker
 * has already resolved the ID, so validation just confirms it
 * exists.
 *
 * `structured_data` is omitted — it's AI-populated, not
 * user-editable through the form.
 */
class JobListingRules
{
    public static function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'role_title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'location' => ['nullable', 'string', 'max:255'],
            'compensation_range' => ['nullable', 'string', 'max:255'],
            'date_posted' => ['nullable', 'date'],
            'status' => ['required', 'string', Rule::enum(JobListingStatus::class)],
        ];
    }

    /**
     * Normalize raw form input. Trims strings, converts empty
     * strings to null on nullable fields so validation treats
     * them as absent.
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

        // Default status to 'active' when not provided (create form
        // doesn't show a status picker — new listings are active).
        if (! isset($cleaned['status'])) {
            $cleaned['status'] = 'active';
        }

        return $cleaned;
    }
}