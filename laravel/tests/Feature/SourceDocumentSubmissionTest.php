<?php

namespace Tests\Feature;

use App\Models\AiUsageEvent;
use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Services\Extraction\DraftRecord;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionProvider;
use App\Services\Extraction\FakeExtractionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end tests for the source document submission flow:
 * store → preview → extract → show.
 *
 * Uses the FakeExtractionProvider (configured by EXTRACTION_DRIVER=fake
 * in phpunit.xml) so no real API calls happen during the test suite.
 */
class SourceDocumentSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function bindFake(callable $configure): FakeExtractionProvider
    {
        $fake = new FakeExtractionProvider();
        $configure($fake);
        $this->app->instance(ExtractionProvider::class, $fake);
        return $fake;
    }

    #[Test]
    public function store_creates_a_source_document_and_redirects_to_preview(): void
    {
        $this->bindFake(fn ($f) => $f->summaryReturns('Generated title')->withTokens(50, 10, 1));

        $response = $this->post(route('source-documents.store'), [
            'body' => 'Some career notes about my time at Lightning Labs',
        ]);

        $document = SourceDocument::firstOrFail();
        $response->assertRedirect(route('source-documents.preview', $document));

        $this->assertSame('Some career notes about my time at Lightning Labs', $document->body);
        $this->assertSame('text', $document->file_type);
        $this->assertSame('other', $document->kind);
        $this->assertSame('Generated title', $document->title);
    }

    #[Test]
    public function store_records_a_summary_usage_event(): void
    {
        $this->bindFake(fn ($f) => $f->summaryReturns('Generated title'));

        $this->post(route('source-documents.store'), ['body' => 'My notes']);

        $event = AiUsageEvent::where('operation', 'summarize_title')->firstOrFail();
        $this->assertSame('fake', $event->provider);
        $this->assertTrue($event->success);
    }

    #[Test]
    public function store_soft_fails_when_title_generation_fails(): void
    {
        $this->bindFake(fn ($f) => $f->throws(new ExtractionException('Simulated')));

        $response = $this->post(route('source-documents.store'), [
            'body' => 'My notes',
        ]);

        $document = SourceDocument::firstOrFail();
        $response->assertRedirect(route('source-documents.preview', $document));
        $this->assertNull($document->title);

        $event = AiUsageEvent::where('operation', 'summarize_title')->firstOrFail();
        $this->assertFalse($event->success);
    }

    #[Test]
    public function store_skips_title_generation_when_user_provides_a_title(): void
    {
        $fake = $this->bindFake(fn ($f) => $f->summaryReturns('Should not be used'));

        $this->post(route('source-documents.store'), [
            'body' => 'My notes',
            'title' => 'My title',
        ]);

        $document = SourceDocument::firstOrFail();
        $this->assertSame('My title', $document->title);
        $this->assertSame(0, $fake->summarizeTitleCallCount);
    }

    #[Test]
    public function store_rejects_an_empty_body(): void
    {
        $this->post(route('source-documents.store'), ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, SourceDocument::count());
    }

    #[Test]
    public function store_rejects_a_body_over_the_character_limit(): void
    {
        $this->post(route('source-documents.store'), [
            'body' => str_repeat('x', 100_001),
        ])->assertSessionHasErrors('body');

        $this->assertSame(0, SourceDocument::count());
    }

    #[Test]
    public function preview_page_loads_for_a_pending_document(): void
    {
        $this->bindFake(fn ($f) => $f->withTokens(500, 0, 0));

        $document = SourceDocument::create([
            'body' => 'My notes',
            'kind' => 'other',
            'file_type' => 'text',
            'title' => 'My document',
        ]);

        $this->get(route('source-documents.preview', $document))
            ->assertOk()
            ->assertSee('My document')
            ->assertSee('Confirm and extract');
    }

    #[Test]
    public function preview_redirects_to_show_when_document_is_already_extracted(): void
    {
        $this->bindFake(fn ($f) => $f);

        $document = SourceDocument::create([
            'body' => 'Body', 'kind' => 'other', 'file_type' => 'text',
        ]);
        // Create one extracted_record so the document is "completed".
        ExtractedRecord::create([
            'source_document_id' => $document->id,
            'record_type' => 'organization',
            'payload' => ['name' => 'Acme'],
        ]);

        $this->get(route('source-documents.preview', $document))
            ->assertRedirect(route('source-documents.show', $document));
    }

    #[Test]
    public function extract_runs_extraction_persists_drafts_and_redirects_to_show(): void
    {
        $this->bindFake(fn ($f) => $f
            ->returns([
                new DraftRecord(type: 'organization', data: ['name' => 'Acme']),
                new DraftRecord(type: 'position', data: ['title' => 'Engineer']),
            ])
            ->withTokens(800, 200, 5)
        );

        $document = SourceDocument::create([
            'body' => 'My notes', 'kind' => 'other', 'file_type' => 'text',
        ]);

        $response = $this->post(route('source-documents.extract', $document));
        $response->assertRedirect(route('source-documents.show', $document));

        $this->assertSame(2, ExtractedRecord::where('source_document_id', $document->id)->count());

        $event = AiUsageEvent::where('operation', 'extract_text')->firstOrFail();
        $this->assertTrue($event->success);
        $this->assertSame($document->id, $event->source_document_id);
    }

    #[Test]
    public function extract_soft_fails_and_redirects_to_show_when_extraction_errors(): void
    {
        $this->bindFake(fn ($f) => $f->throws(new ExtractionException('Simulated')));

        $document = SourceDocument::create([
            'body' => 'My notes', 'kind' => 'other', 'file_type' => 'text',
        ]);

        $response = $this->post(route('source-documents.extract', $document));
        $response->assertRedirect(route('source-documents.show', $document));

        $this->assertSame(0, ExtractedRecord::count());
        $event = AiUsageEvent::where('operation', 'extract_text')->firstOrFail();
        $this->assertFalse($event->success);
    }

    #[Test]
    public function destroy_deletes_a_pending_document_and_redirects_home(): void
    {
        $document = SourceDocument::create([
            'body' => 'My notes', 'kind' => 'other', 'file_type' => 'text',
        ]);

        $this->delete(route('source-documents.destroy', $document))
            ->assertRedirect(route('career-input.index'));

        $this->assertSoftDeleted($document);
    }
}