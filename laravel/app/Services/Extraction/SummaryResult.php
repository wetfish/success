<?php

namespace App\Services\Extraction;

/**
 * The result of a summarizeTitle() call. The generated short title
 * (3-7 words) plus the telemetry needed to record an AiUsageEvent.
 *
 * Structurally similar to SynthesisResult but semantically distinct:
 * synthesis combines two existing descriptions into one full unified
 * version; summarization condenses a longer body into a short label
 * suitable for use as a document title.
 */
class SummaryResult
{
    public function __construct(
        public readonly string $title,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costCents,
        public readonly string $model,
    ) {}
}