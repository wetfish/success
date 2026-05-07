<?php

namespace App\Services\Extraction;

use App\Models\SourceDocument;

/**
 * Interface every AI extraction provider implements. Lets us swap
 * between Claude, future Ollama, or other providers without changing
 * the rest of the app.
 *
 * The interface is intentionally small. Provider-specific knobs
 * (model name, temperature, system prompt, request shape) are
 * implementation details configured via the constructor — they don't
 * leak into the interface.
 */
interface ExtractionProvider
{
    /**
     * Extract structured drafts from a source document.
     *
     * @throws ExtractionException on API errors, malformed responses,
     *                             unparseable JSON, or other failures
     */
    public function extract(SourceDocument $document): ExtractionResult;

    /**
     * Synthesize a unified description from two existing ones. Used
     * when duplicate detection finds a candidate match and the user
     * chooses to merge rather than discard or overwrite.
     *
     * @throws ExtractionException
     */
    public function synthesize(string $existing, string $new): SynthesisResult;

    /**
     * Estimate the input token count for a source document without
     * making the full extraction call. Cheaper than extract() and
     * useful for cost previews.
     *
     * @return int  estimated input tokens
     *
     * @throws ExtractionException
     */
    public function estimateTokens(SourceDocument $document): int;

    /**
     * True when this provider is configured and reachable. Verifies
     * both that credentials are present and that the API responds
     * to a minimal probe.
     */
    public function isAvailable(): bool;

    /**
     * Identifier used when recording AiUsageEvent rows. Lets us
     * filter usage by provider in reports.
     */
    public function name(): string;
}