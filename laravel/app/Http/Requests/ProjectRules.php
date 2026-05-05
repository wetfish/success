<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules and input normalization for Project form
 * requests. Both StoreProjectRequest and UpdateProjectRequest delegate
 * here, so create and edit forms validate identically.
 *
 * The interesting work in this class is normalize(), which handles
 * the date precision conversion. The form sends raw input fields that
 * vary by precision (a date, a month, a quarter+year, or just a year),
 * and normalize() converts them into real start_date and end_date
 * values stored in the database per the schema convention:
 *   - start_date is the first day of the period
 *   - end_date is the last day of the period
 * This keeps date math working for sorting and overlap detection
 * regardless of the precision the user entered.
 */
class ProjectRules
{
    public const VISIBILITIES = [
        'public',
        'open_source',
        'internal',
        'confidential',
    ];

    public const STATUSES = [
        'live',
        'archived',
        'killed',
        'prototype',
        'ongoing',
    ];

    public const CONTRIBUTION_LEVELS = [
        'lead',
        'core',
        'contributor',
        'occasional',
        'reviewer',
    ];

    public const DATE_PRECISIONS = [
        'day',
        'month',
        'quarter',
        'year',
    ];

    public static function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'parent_project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'name' => ['required', 'string', 'max:255'],
            'public_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'problem' => ['nullable', 'string', 'max:5000'],
            'constraints' => ['nullable', 'string', 'max:5000'],
            'approach' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', 'string', 'max:5000'],
            'rationale' => ['nullable', 'string', 'max:5000'],
            'date_precision' => ['required', 'string', Rule::in(self::DATE_PRECISIONS)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'visibility' => ['required', 'string', Rule::in(self::VISIBILITIES)],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'contribution_level' => ['required', 'string', Rule::in(self::CONTRIBUTION_LEVELS)],
            'contribution_type' => ['nullable', 'string', 'max:255'],
            'team_size' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'user_notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * Normalize raw form input. Trims strings, converts empty strings
     * to null, strips commas from team_size, and resolves the precision-
     * specific date inputs into real start_date and end_date values.
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

        if (isset($cleaned['team_size']) && is_string($cleaned['team_size'])) {
            $cleaned['team_size'] = str_replace([',', ' '], '', $cleaned['team_size']);
        }

        $precision = $cleaned['date_precision'] ?? null;

        $cleaned['start_date'] = self::resolveDate(
            $precision,
            'start',
            $cleaned['start_date_day'] ?? null,
            $cleaned['start_date_month'] ?? null,
            $cleaned['start_date_quarter'] ?? null,
            $cleaned['start_date_year'] ?? null,
        );

        $cleaned['end_date'] = self::resolveDate(
            $precision,
            'end',
            $cleaned['end_date_day'] ?? null,
            $cleaned['end_date_month'] ?? null,
            $cleaned['end_date_quarter'] ?? null,
            $cleaned['end_date_year'] ?? null,
        );

        // Strip the precision-specific helper inputs — they are not
        // columns on the projects table.
        unset(
            $cleaned['start_date_day'], $cleaned['start_date_month'],
            $cleaned['start_date_quarter'], $cleaned['start_date_year'],
            $cleaned['end_date_day'], $cleaned['end_date_month'],
            $cleaned['end_date_quarter'], $cleaned['end_date_year'],
        );

        return $cleaned;
    }

    /**
     * Convert precision-specific date inputs into a real date string.
     * For start_date, returns the first day of the period; for end_date,
     * returns the last day of the period. Returns null if the user
     * didn't provide enough input to resolve a date at the chosen precision.
     */
    private static function resolveDate(
        ?string $precision,
        string $boundary,
        ?string $dayInput,
        ?string $monthInput,
        ?string $quarterInput,
        ?string $yearInput,
    ): ?string {
        if ($precision === null) {
            return null;
        }

        try {
            switch ($precision) {
                case 'day':
                    if (! $dayInput) {
                        return null;
                    }
                    return Carbon::parse($dayInput)->toDateString();

                case 'month':
                    // <input type="month"> returns YYYY-MM
                    if (! $monthInput) {
                        return null;
                    }
                    $date = Carbon::createFromFormat('Y-m', $monthInput)->startOfMonth();
                    return $boundary === 'start'
                        ? $date->toDateString()
                        : $date->endOfMonth()->toDateString();

                case 'quarter':
                    if (! $quarterInput || ! $yearInput) {
                        return null;
                    }
                    $quarter = (int) $quarterInput;
                    $year = (int) $yearInput;
                    if ($quarter < 1 || $quarter > 4) {
                        return null;
                    }
                    $startMonth = ($quarter - 1) * 3 + 1;
                    $date = Carbon::create($year, $startMonth, 1);
                    return $boundary === 'start'
                        ? $date->toDateString()
                        : $date->addMonths(2)->endOfMonth()->toDateString();

                case 'year':
                    if (! $yearInput) {
                        return null;
                    }
                    $year = (int) $yearInput;
                    $date = Carbon::create($year, 1, 1);
                    return $boundary === 'start'
                        ? $date->toDateString()
                        : Carbon::create($year, 12, 31)->toDateString();
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }
}