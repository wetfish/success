<?php

namespace App\Services\Extraction;

use App\Models\SourceDocument;
use Illuminate\Support\Collection;

/**
 * Test double for ExtractionProvider. Tests configure expected drafts
 * and the fake returns them without any network calls.
 *
 * Usage in a test:
 *
 *   $fake = new FakeExtractionProvider();
 *   $fake->returns([
 *       new DraftRecord(type: 'organization', data: ['name' => 'Acme']),
 *   ]);
 *   $this->app->instance(ExtractionProvider::class, $fake);
 */
class FakeExtractionProvider implements ExtractionProvider
{
    /** @var Collection<int, DraftRecord> */
    private Collection $stubDrafts;

    private bool $available = true;
    private int $tokensToReturn = 100;
    private int $costCentsToReturn = 1;
    private string $synthesisToReturn = 'Synthesized description';
    private ?ExtractionException $exceptionToThrow = null;

    public int $extractCallCount = 0;
    public int $synthesizeCallCount = 0;

    public function __construct()
    {
        $this->stubDrafts = collect();
    }

    public function name(): string
    {
        return 'fake';
    }

    /**
     * @param  array<int, DraftRecord>  $drafts
     */
    public function returns(array $drafts): self
    {
        $this->stubDrafts = collect($drafts);
        return $this;
    }

    public function available(bool $value): self
    {
        $this->available = $value;
        return $this;
    }

    public function withTokens(int $input, int $output, int $costCents): self
    {
        $this->tokensToReturn = $input + $output;
        $this->costCentsToReturn = $costCents;
        return $this;
    }

    public function synthesisReturns(string $value): self
    {
        $this->synthesisToReturn = $value;
        return $this;
    }

    public function throws(ExtractionException $e): self
    {
        $this->exceptionToThrow = $e;
        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function extract(SourceDocument $document): ExtractionResult
    {
        $this->extractCallCount++;

        if ($this->exceptionToThrow) {
            throw $this->exceptionToThrow;
        }

        return new ExtractionResult(
            drafts: $this->stubDrafts,
            inputTokens: (int) ($this->tokensToReturn * 0.7),
            outputTokens: (int) ($this->tokensToReturn * 0.3),
            costCents: $this->costCentsToReturn,
            model: 'fake-model',
        );
    }

    public function synthesize(string $existing, string $new): SynthesisResult
    {
        $this->synthesizeCallCount++;

        if ($this->exceptionToThrow) {
            throw $this->exceptionToThrow;
        }

        return new SynthesisResult(
            description: $this->synthesisToReturn,
            inputTokens: 50,
            outputTokens: 50,
            costCents: $this->costCentsToReturn,
            model: 'fake-model',
        );
    }

    public function estimateTokens(SourceDocument $document): int
    {
        return $this->tokensToReturn;
    }
}