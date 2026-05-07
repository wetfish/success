<?php

namespace Tests\Feature;

use App\Models\AiUsageEvent;
use App\Models\SourceDocument;
use App\Services\AiUsageTracker;
use App\Services\Extraction\DraftRecord;
use App\Services\Extraction\ExtractionResult;
use App\Services\Extraction\SynthesisResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiUsageTrackerTest extends TestCase
{
    use RefreshDatabase;

    private function makeDocument(): SourceDocument
    {
        return SourceDocument::create([
            'title' => 'Test doc',
            'kind' => 'other',
            'file_type' => 'text',
            'body' => 'Body',
        ]);
    }

    #[Test]
    public function it_records_extraction_results(): void
    {
        $tracker = new AiUsageTracker();
        $document = $this->makeDocument();

        $result = new ExtractionResult(
            drafts: collect([new DraftRecord('organization', ['name' => 'Acme'])]),
            inputTokens: 1000,
            outputTokens: 500,
            costCents: 12,
            model: 'claude-sonnet-4-6',
        );

        $event = $tracker->recordExtraction(
            result: $result,
            provider: 'claude',
            operation: 'extract_text',
            document: $document,
        );

        $this->assertSame('claude', $event->provider);
        $this->assertSame('claude-sonnet-4-6', $event->model);
        $this->assertSame('extract_text', $event->operation);
        $this->assertSame($document->id, $event->source_document_id);
        $this->assertSame(1000, $event->input_tokens);
        $this->assertSame(500, $event->output_tokens);
        $this->assertSame(12, $event->cost_cents);
        $this->assertTrue($event->success);
    }

    #[Test]
    public function it_records_synthesis_results(): void
    {
        $tracker = new AiUsageTracker();

        $result = new SynthesisResult(
            description: 'Combined text',
            inputTokens: 200,
            outputTokens: 100,
            costCents: 3,
            model: 'claude-sonnet-4-6',
        );

        $event = $tracker->recordSynthesis(
            result: $result,
            provider: 'claude',
        );

        $this->assertSame('synthesize', $event->operation);
        $this->assertNull($event->source_document_id);
        $this->assertSame(200, $event->input_tokens);
        $this->assertSame(100, $event->output_tokens);
        $this->assertSame(3, $event->cost_cents);
    }

    #[Test]
    public function it_records_failures(): void
    {
        $tracker = new AiUsageTracker();
        $document = $this->makeDocument();

        $event = $tracker->recordFailure(
            provider: 'claude',
            model: 'claude-sonnet-4-6',
            operation: 'extract_text',
            errorMessage: 'API returned 500',
            document: $document,
        );

        $this->assertFalse($event->success);
        $this->assertSame('API returned 500', $event->error_message);
        $this->assertSame(0, $event->input_tokens);
        $this->assertSame(0, $event->cost_cents);
        $this->assertSame($document->id, $event->source_document_id);
    }

    #[Test]
    public function multiple_events_can_be_recorded_against_one_document(): void
    {
        $tracker = new AiUsageTracker();
        $document = $this->makeDocument();

        $result = new ExtractionResult(
            drafts: collect(),
            inputTokens: 100,
            outputTokens: 50,
            costCents: 2,
            model: 'claude-sonnet-4-6',
        );

        $tracker->recordExtraction($result, 'claude', 'extract_text', $document);
        $tracker->recordExtraction($result, 'claude', 'extract_text', $document);

        $this->assertSame(2, AiUsageEvent::where('source_document_id', $document->id)->count());
    }
}