<?php

namespace App\Services\Extraction;

/**
 * The result of a synthesize() call. The unified description plus
 * the telemetry needed to record an AiUsageEvent.
 */
class SynthesisResult
{
    public function __construct(
        public readonly string $description,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costCents,
        public readonly string $model,
    ) {}
}