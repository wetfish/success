<?php

namespace App\Services\Resume;

use Illuminate\Support\Collection;

/**
 * The result of a relevance analysis call. Holds the AI's suggestions
 * (which catalog entries to include and why) plus token telemetry.
 *
 * Each suggestion is an associative array with:
 *   type     — e.g. "Position", "Project", "Accomplishment", etc.
 *   id       — the database ID of the catalog record
 *   reason   — the AI's explanation for why this entry is relevant
 *   order    — suggested display order within the type group
 *
 * Cost is in cents per the Money helper convention.
 */
class RelevanceResult
{
    /**
     * @param  Collection<int, array{type: string, id: int, reason: string, order: int}>  $suggestions
     */
    public function __construct(
        public readonly Collection $suggestions,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costCents,
        public readonly string $model,
    ) {}
}