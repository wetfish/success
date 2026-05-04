<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Helpers for working with monetary values.
 *
 * Per the project's money convention (see docs/05-ai-development-notes.md),
 * all monetary values are stored in the database as integer cents.
 * Conversion to and from human-readable dollar strings happens at the
 * application boundary — form requests, views, AI input/output — using
 * the methods on this class.
 *
 * Models themselves do not transform monetary values; they store and
 * return raw integer cents. This keeps the model layer predictable and
 * makes the conversion boundary explicit.
 */
class Money
{
    /**
     * Format an integer cents value as a dollar string with two decimals.
     *
     * Returns null for null input so this can chain through nullable
     * monetary fields without conditionals. No currency symbol or
     * thousands separators are applied; consumers can format further
     * for locale-specific display.
     *
     * Example: format(15000000) returns "150000.00"
     */
    public static function format(?int $cents): ?string
    {
        if ($cents === null) {
            return null;
        }

        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Parse a human-readable monetary string into integer cents.
     *
     * Tolerant of common user input variations:
     *   - Currency symbols ($, €, £, ¥) are stripped
     *   - Thousands separators (commas, spaces) are stripped
     *   - Decimal points are recognized as cent separators
     *   - Leading and trailing whitespace is ignored
     *
     * Returns null for null or empty input.
     * Throws InvalidArgumentException for input that can't be interpreted.
     *
     * Example: parse("$70,000,000") returns 7000000000
     */
    public static function parse(?string $input): ?int
    {
        if ($input === null) {
            return null;
        }

        $trimmed = trim($input);

        if ($trimmed === '') {
            return null;
        }

        // Strip currency symbols and thousands separators
        $cleaned = preg_replace('/[$€£¥,\s]/u', '', $trimmed);

        if (! is_numeric($cleaned)) {
            throw new InvalidArgumentException(
                "Could not parse '{$input}' as a monetary value."
            );
        }

        // Use round() to avoid float precision issues
        // (e.g., 0.07 * 100 evaluates to 6.999... in raw float arithmetic)
        return (int) round((float) $cleaned * 100);
    }
}