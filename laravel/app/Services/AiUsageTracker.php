<?php

namespace App\Services;

use App\Models\AiUsageEvent;
use App\Models\SourceDocument;
use App\Services\Extraction\ExtractionResult;
use App\Services\Extraction\SynthesisResult;

/**
 * Records AI API calls to the ai_usage_events table. Decoupled from
 * the providers themselves so callers (controllers, artisan commands,
 * tests) can choose whether to persist usage on a given call.
 */
class AiUsageTracker
{
    public function recordExtraction(
        ExtractionResult $result,
        string $provider,
        string $operation,
        ?SourceDocument $document = null,
    ): AiUsageEvent {
        return AiUsageEvent::create([
            'provider' => $provider,
            'model' => $result->model,
            'operation' => $operation,
            'source_document_id' => $document?->id,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cost_cents' => $result->costCents,
            'success' => true,
        ]);
    }

    public function recordSynthesis(
        SynthesisResult $result,
        string $provider,
    ): AiUsageEvent {
        return AiUsageEvent::create([
            'provider' => $provider,
            'model' => $result->model,
            'operation' => 'synthesize',
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cost_cents' => $result->costCents,
            'success' => true,
        ]);
    }

    public function recordFailure(
        string $provider,
        string $model,
        string $operation,
        string $errorMessage,
        ?SourceDocument $document = null,
    ): AiUsageEvent {
        return AiUsageEvent::create([
            'provider' => $provider,
            'model' => $model,
            'operation' => $operation,
            'source_document_id' => $document?->id,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_cents' => 0,
            'success' => false,
            'error_message' => $errorMessage,
        ]);
    }
}