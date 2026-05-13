<?php

namespace App\Enums;

/**
 * Canonical list of organization statuses. Captures the company's
 * current operational state at the time of the user's interaction
 * with it — useful for resume framing ("worked at X (acquired)")
 * and for filtering when the catalog gets larger.
 *
 * See OrganizationType for the broader rationale on enum-as-
 * single-source-of-truth.
 */
enum OrganizationStatus: string
{
    case Active = 'active';
    case Acquired = 'acquired';
    case Defunct = 'defunct';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Acquired => 'Acquired',
            self::Defunct => 'Defunct',
            self::Unknown => 'Unknown',
        };
    }

    public static function promptEnumString(): string
    {
        return collect(self::cases())
            ->map(fn (self $case) => '"' . $case->value . '"')
            ->implode(' | ');
    }
}