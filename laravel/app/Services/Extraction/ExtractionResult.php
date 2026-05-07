<?php

namespace App\Services\Extraction;

use Illuminate\Support\Collection;

/**
 * The result of a single extract() call. Holds the drafts produced
 * plus the telemetry needed to record an AiUsageEvent.
 *
 * Cost is in cents per the Money helper convention.
 */
class ExtractionResult
{
    /**
     * @param  Collection<int, DraftRecord>  $drafts
     */
    public function __construct(
        public readonly Collection $drafts,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costCents,
        public readonly string $model,
    ) {}
}