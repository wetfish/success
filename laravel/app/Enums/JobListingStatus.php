<?php

namespace App\Enums;

/**
 * Canonical list of job listing statuses. Tracks whether a listing
 * is still open or has been closed (filled, expired, or withdrawn).
 *
 * Only two cases for MVP. Additional states (e.g., `expired`,
 * `withdrawn`) can be added as cases here when the distinction
 * becomes useful for filtering or reporting.
 *
 * No `promptEnumString()` — job listing statuses aren't referenced
 * in the AI extraction prompt. Add the method if that changes.
 */
enum JobListingStatus: string
{
    case Active = 'active';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Closed => 'Closed',
        };
    }
}