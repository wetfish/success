<?php

namespace App\Enums;

/**
 * Which section of the job listing a requirement came from.
 *
 *   required       — hard requirements ("Requirements" section)
 *   preferred      — nice-to-haves ("Nice to Have" section)
 *   responsibility — day-to-day duties ("What You'll Do" section)
 *
 * All three matter for resume framing: the user wants to show they
 * meet requirements, have bonus skills, and have done similar work.
 * The review page groups by section so the user can prioritize
 * addressing hard requirements first.
 */
enum RequirementSection: string
{
    case Required = 'required';
    case Preferred = 'preferred';
    case Responsibility = 'responsibility';

    public function label(): string
    {
        return match ($this) {
            self::Required => 'Required',
            self::Preferred => 'Nice to Have',
            self::Responsibility => 'Responsibility',
        };
    }
}