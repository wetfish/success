<?php

namespace App\Http\Requests;

/**
 * Shared validation rules and input normalization for Accomplishment
 * form requests.
 *
 * The CONFIDENCE_LABELS and PROMINENCE_LABELS arrays live here as
 * constants because they're used in both views (rendering the slider
 * label) and any future code that needs to display these scores in
 * human terms (e.g., the AI extraction confirmation UI). Single source
 * of truth.
 */
class AccomplishmentRules
{
    public const DATING_TYPES = ['date', 'period'];

    public const CONFIDENCE_LABELS = [
        1 => 'Rough estimate',
        2 => 'Approximate',
        3 => 'Reasonable',
        4 => 'Confident',
        5 => 'Verified',
    ];

    public const PROMINENCE_LABELS = [
        1 => 'Background',
        2 => 'Minor',
        3 => 'Standard',
        4 => 'Strong',
        5 => 'Featured',
    ];

    public static function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'description' => ['required', 'string', 'max:5000'],
            'impact_metric' => ['nullable', 'string', 'max:255'],
            'impact_value' => ['nullable', 'string', 'max:255'],
            'impact_unit' => ['nullable', 'string', 'max:255'],
            'confidence' => ['required', 'integer', 'min:1', 'max:5'],
            'prominence' => ['required', 'integer', 'min:1', 'max:5'],
            'context_notes' => ['nullable', 'string', 'max:5000'],
            'date' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ];
    }

    /**
     * Normalize raw form input. The "dating_type" radio (date vs.
     * period) drives which fields are kept and which are stripped, so
     * the model-level XOR validators don't fire on stale field values.
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

        // The dating_type radio determines which date fields apply.
        // Strip the unused side so the model's XOR validator passes.
        $datingType = $cleaned['dating_type'] ?? null;

        if ($datingType === 'date') {
            $cleaned['period_start'] = null;
            $cleaned['period_end'] = null;
        } elseif ($datingType === 'period') {
            $cleaned['date'] = null;
        }

        // dating_type itself is a UI helper, not a column.
        unset($cleaned['dating_type']);

        return $cleaned;
    }
}