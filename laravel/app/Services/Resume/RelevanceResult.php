<?php

namespace App\Services\Resume;

use Illuminate\Support\Collection;

/**
 * The result of a full resume analysis call. The AI produces three
 * things in one response:
 *
 *   requirements — structured requirements extracted from the listing,
 *     each with a category, section (required/preferred/responsibility),
 *     title, and description. Array shape:
 *       {ref: string, category: string, title: string,
 *        description: string, section: string, order: int}
 *     The `ref` is a short AI-assigned label (e.g., "REQ-1") used
 *     to link selections to requirements within the same response.
 *
 *   strategySummary — a paragraph describing the recommended narrative
 *     angle for the application
 *
 *   selections — catalog entries mapped to specific requirements,
 *     with reasoning for each. Array shape:
 *       {type: string, id: int, requirement_ref: string|null,
 *        reason: string, order: int}
 *     The `requirement_ref` ties back to a requirement's `ref` field.
 *     Null means the selection is general resume content not tied to
 *     a specific requirement.
 *
 * Cost is in cents per the Money helper convention.
 */
class RelevanceResult
{
    /**
     * @param  Collection<int, array>  $requirements
     * @param  string  $strategySummary
     * @param  Collection<int, array>  $selections
     */
    public function __construct(
        public readonly Collection $requirements,
        public readonly string $strategySummary,
        public readonly Collection $selections,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costCents,
        public readonly string $model,
    ) {}
}