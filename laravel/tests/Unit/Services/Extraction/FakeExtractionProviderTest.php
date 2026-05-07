<?php

namespace Tests\Unit\Services\Extraction;

use App\Models\SourceDocument;
use App\Services\Extraction\DraftRecord;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\FakeExtractionProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FakeExtractionProviderTest extends TestCase
{
    private function makeDocument(): SourceDocument
    {
        return new SourceDocument([
            'title' => 'Test',
            'kind' => 'other',
            'file_type' => 'text',
            'body' => 'Test body',
        ]);
    }

    #[Test]
    public function it_returns_configured_drafts(): void
    {
        $fake = new FakeExtractionProvider();
        $fake->returns([
            new DraftRecord(type: 'organization', data: ['name' => 'Acme']),
            new DraftRecord(type: 'position', data: ['title' => 'Engineer']),
        ]);

        $result = $fake->extract($this->makeDocument());

        $this->assertCount(2, $result->drafts);
        $this->assertSame('Acme', $result->drafts[0]->data['name']);
        $this->assertSame('Engineer', $result->drafts[1]->data['title']);
    }

    #[Test]
    public function it_returns_configured_token_counts(): void
    {
        $fake = new FakeExtractionProvider();
        $fake->withTokens(input: 70, output: 30, costCents: 5);

        $result = $fake->extract($this->makeDocument());

        $this->assertSame(70, $result->inputTokens);
        $this->assertSame(30, $result->outputTokens);
        $this->assertSame(5, $result->costCents);
    }

    #[Test]
    public function it_throws_when_configured_to_throw(): void
    {
        $fake = new FakeExtractionProvider();
        $fake->throws(new ExtractionException('Simulated failure'));

        $this->expectException(ExtractionException::class);
        $this->expectExceptionMessage('Simulated failure');

        $fake->extract($this->makeDocument());
    }

    #[Test]
    public function it_reports_availability(): void
    {
        $fake = new FakeExtractionProvider();
        $this->assertTrue($fake->isAvailable());

        $fake->available(false);
        $this->assertFalse($fake->isAvailable());
    }

    #[Test]
    public function it_counts_extract_calls(): void
    {
        $fake = new FakeExtractionProvider();
        $document = $this->makeDocument();

        $fake->extract($document);
        $fake->extract($document);
        $fake->extract($document);

        $this->assertSame(3, $fake->extractCallCount);
    }

    #[Test]
    public function it_synthesizes(): void
    {
        $fake = new FakeExtractionProvider();
        $fake->synthesisReturns('Combined description here');

        $result = $fake->synthesize('Old', 'New');

        $this->assertSame('Combined description here', $result->description);
        $this->assertSame(1, $fake->synthesizeCallCount);
    }
}