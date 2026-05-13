<?php

namespace App\Enums;

/**
 * Canonical list of organization types.
 *
 * Single source of truth for the values: validation rules, form
 * controls, the AI extraction prompt, and the draft review UI all
 * derive from this enum. See docs/07-conventions.md for the
 * "single source of truth" pattern this establishes (landing in
 * the docs slice).
 *
 * `prospect` is deliberately distinct from `employer`: it marks
 * companies the user is researching or applying to, separate from
 * past employment history. This lets the catalog cleanly answer
 * two different questions — "what's my career history" filters
 * out prospects, "what's my active job search" filters in only
 * prospects.
 *
 * No model cast on Organization yet — the column remains a raw
 * string at the DB layer. This enum currently serves as the
 * source-of-truth for valid values, validation, and display, but
 * `$organization->type` returns a string, not an enum instance.
 * Graduating to a full Eloquent cast is a mechanical follow-up
 * that touches the test suite (assertions against string values
 * become assertions against enum cases) and can land separately
 * when the type-safety benefit is worth the test churn.
 */
enum OrganizationType: string
{
    case Employer = 'employer';
    case Client = 'client';
    case Personal = 'personal';
    case OpenSource = 'open_source';
    case Volunteer = 'volunteer';
    case Educational = 'educational';
    case Prospect = 'prospect';

    /**
     * Human-readable display label for this case. Used by form
     * selects, the index page, and anywhere a user-facing string
     * is rendered. Defined explicitly rather than derived from the
     * value (via `ucfirst(str_replace('_', ' ', …))`) because some
     * labels (e.g., "Open source") read better with intentional
     * casing rather than the default ucfirst behavior on every
     * word boundary.
     */
    public function label(): string
    {
        return match ($this) {
            self::Employer => 'Employer',
            self::Client => 'Client',
            self::Personal => 'Personal',
            self::OpenSource => 'Open source',
            self::Volunteer => 'Volunteer',
            self::Educational => 'Educational',
            self::Prospect => 'Prospect',
        };
    }

    /**
     * Pipe-delimited list of all values, each quoted, for
     * interpolation into the AI extraction prompt. The prompt
     * format looks like:
     *
     *   type ("employer" | "client" | "personal" | ...)
     *
     * Interpolating this method's output keeps the prompt in
     * sync with the enum automatically — adding a new case
     * propagates to the AI's allowed values without anyone
     * touching the extraction provider.
     *
     * Output uses double-quotes around values because that's the
     * JSON-string convention the AI is emitting. Single-quote
     * variants can be derived if needed, but no current consumer
     * needs them.
     */
    public static function promptEnumString(): string
    {
        return collect(self::cases())
            ->map(fn (self $case) => '"' . $case->value . '"')
            ->implode(' | ');
    }
}