<?php

namespace App\Http\Requests;

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
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:5000'],
            'impact_metric' => ['nullable', 'string', 'max:255'],
            'impact_value' => ['nullable', 'string', 'max:255'],
            'impact_unit' => ['nullable', 'string', 'max:255'],
            'confidence' => ['required', 'integer', 'min:1', 'max:5'],
            'prominence' => ['required', 'integer', 'min:1', 'max:5'],
            'context_notes' => ['nullable', 'string', 'max:5000'],

            // Dating: exactly one of `date` or `period_start` must be set.
            // The model layer enforces this as a structural invariant,
            // but we surface it at the form layer so users get friendly
            // errors instead of 500s.
            //
            // - `required_without` catches the "neither set" case.
            // - `prohibits` catches the "both set" case. Crucially,
            //   `prohibits` operates on validated data (post-normalize),
            //   so stale values cleared by normalize() don't trigger
            //   false-positive errors.
            'date' => ['nullable', 'date', 'required_without:period_start', 'prohibits:period_start'],
            'period_start' => ['nullable', 'date', 'required_without:date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ];
    }

    public static function messages(): array
    {
        return [
            'date.required_without' => 'Pick a date or a period of time.',
            'period_start.required_without' => 'Pick a date or a period of time.',
            'date.prohibits' => 'Choose either a single date or a period, not both.',
        ];
    }

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

        $datingType = $cleaned['dating_type'] ?? null;

        if ($datingType === 'date') {
            $cleaned['period_start'] = null;
            $cleaned['period_end'] = null;
        } elseif ($datingType === 'period') {
            $cleaned['date'] = null;
        }

        unset($cleaned['dating_type']);

        return $cleaned;
    }
}