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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
    public function extract_also_creates_review_records_from_nested_entity_data(): void
    {
        // Feature-level confirmation that the controller wires
        // ReviewRecordExtractor into the extract flow. The unit tests
        // cover dedup, matching, and the rest in detail; this test
        // verifies the integration — entity drafts AND review records
        // both end up persisted after a POST to the extract endpoint.
        //
        // Pre-create one tag in the catalog so we can verify the
        // match_record_id pre-compute path fires through the
        // controller. Mirror tag emission name+category exactly so
        // the resolver finds it.
        $existingTag = \App\Models\Tag::create(['name' => 'Python', 'category' => 'language']);

        $this->bindFake(fn ($f) => $f
            ->returns([
                new DraftRecord(type: 'organization', data: ['name' => 'Acme']),
                new DraftRecord(type: 'project', data: [
                    'organization_name' => 'Acme',
                    'name' => 'Migration',
                    'tags' => [
                        ['name' => 'Python', 'category' => 'language'],     // matches existing
                        ['name' => 'Kubernetes', 'category' => 'tool'],     // new
                    ],
                    'collaborators' => [
                        ['name' => 'Sarah Chen', 'role' => 'Manager'],
                    ],
                    'links' => [
                        ['url' => 'https://github.com/acme/migration', 'type' => 'github'],
                    ],
                ]),
            ])
        );

        $document = SourceDocument::create([
            'body' => 'My notes', 'kind' => 'other', 'file_type' => 'text',
        ]);

        $this->post(route('source-documents.extract', $document));

        // 2 entity drafts + 2 tag review records + 1 person review record
        // + 1 link review record = 6 total.
        $this->assertSame(6, ExtractedRecord::where('source_document_id', $document->id)->count());

        $pythonReview = ExtractedRecord::where('source_document_id', $document->id)
            ->where('record_type', 'tag')
            ->where('payload->extracted_name', 'Python')
            ->first();
        $this->assertNotNull($pythonReview);
        $this->assertSame($existingTag->id, $pythonReview->match_record_id);
        // Matched at derivation → auto-confirmed (no decision left for review).
        $this->assertSame('confirmed', $pythonReview->status);

        $kubernetesReview = ExtractedRecord::where('source_document_id', $document->id)
            ->where('record_type', 'tag')
            ->where('payload->extracted_name', 'Kubernetes')
            ->first();
        $this->assertNotNull($kubernetesReview);
        $this->assertNull($kubernetesReview->match_record_id);
        // Unmatched → pending (the review UI will surface it).
        $this->assertSame('pending', $kubernetesReview->status);
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

    /* ===================================================================
     * File upload submissions.
     * =================================================================== */

    #[Test]
    public function uploading_a_text_file_reads_contents_into_body(): void
    {
        $this->bindFake(fn ($f) => $f);
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent(
            'My_career_notes.txt',
            'These are my career notes from the file.'
        );

        $this->post(route('source-documents.store'), ['upload' => $file]);

        $document = SourceDocument::firstOrFail();
        $this->assertSame('These are my career notes from the file.', $document->body);
        $this->assertSame('text', $document->file_type);
        $this->assertNull($document->file_path);
        // Title comes from the filename — extension stripped, underscores
        // converted to spaces.
        $this->assertSame('My career notes', $document->title);
    }

    #[Test]
    public function uploading_a_markdown_file_reads_contents_and_sets_markdown_type(): void
    {
        $this->bindFake(fn ($f) => $f);
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent(
            'brag-doc.md',
            "# Brag doc\n\nSome accomplishments here."
        );

        $this->post(route('source-documents.store'), ['upload' => $file]);

        $document = SourceDocument::firstOrFail();
        $this->assertSame("# Brag doc\n\nSome accomplishments here.", $document->body);
        $this->assertSame('markdown', $document->file_type);
        $this->assertNull($document->file_path);
        // Hyphen → space too.
        $this->assertSame('brag doc', $document->title);
    }

    #[Test]
    public function uploading_a_pdf_persists_the_file_and_leaves_body_null(): void
    {
        $this->bindFake(fn ($f) => $f);
        Storage::fake('local');

        $file = UploadedFile::fake()->create('Lightning Labs resume.pdf', 100, 'application/pdf');

        $this->post(route('source-documents.store'), ['upload' => $file]);

        $document = SourceDocument::firstOrFail();
        $this->assertNull($document->body);
        $this->assertSame('pdf', $document->file_type);
        $this->assertNotNull($document->file_path);
        $this->assertStringStartsWith('source-documents/', $document->file_path);
        $this->assertStringEndsWith('.pdf', $document->file_path);
        $this->assertSame('Lightning Labs resume', $document->title);
        Storage::disk('local')->assertExists($document->file_path);
    }

    #[Test]
    public function uploading_a_file_skips_ai_title_generation(): void
    {
        $fake = $this->bindFake(fn ($f) => $f->summaryReturns('Should not be used'));
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('notes.txt', 'Some content');

        $this->post(route('source-documents.store'), ['upload' => $file]);

        $this->assertSame(0, $fake->summarizeTitleCallCount);
        // No summarize_title usage event either.
        $this->assertSame(0, AiUsageEvent::where('operation', 'summarize_title')->count());
    }

    #[Test]
    public function rejects_files_with_disallowed_extensions(): void
    {
        $this->bindFake(fn ($f) => $f);
        Storage::fake('local');

        $file = UploadedFile::fake()->create('script.exe', 10);

        $this->post(route('source-documents.store'), ['upload' => $file])
            ->assertSessionHasErrors('upload');

        $this->assertSame(0, SourceDocument::count());
    }

    #[Test]
    public function rejects_files_over_the_size_limit(): void
    {
        $this->bindFake(fn ($f) => $f);
        Storage::fake('local');

        // 10240 KB is the limit; 11000 KB exceeds it.
        $file = UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf');

        $this->post(route('source-documents.store'), ['upload' => $file])
            ->assertSessionHasErrors('upload');

        $this->assertSame(0, SourceDocument::count());
    }

    #[Test]
    public function rejects_submission_with_both_body_and_file(): void
    {
        $this->bindFake(fn ($f) => $f);
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('notes.txt', 'File content');

        $this->post(route('source-documents.store'), [
            'body' => 'Pasted body',
            'upload' => $file,
        ])->assertSessionHasErrors();

        $this->assertSame(0, SourceDocument::count());
    }

    #[Test]
    public function rejects_submission_with_neither_body_nor_file(): void
    {
        $this->bindFake(fn ($f) => $f);

        $this->post(route('source-documents.store'), [])
            ->assertSessionHasErrors();

        $this->assertSame(0, SourceDocument::count());
    }

    #[Test]
    public function destroying_a_pdf_document_removes_the_file_from_disk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('source-documents/test.pdf', 'fake pdf content');

        $document = SourceDocument::create([
            'kind' => 'other',
            'file_type' => 'pdf',
            'file_path' => 'source-documents/test.pdf',
            'title' => 'Test PDF',
        ]);

        $this->delete(route('source-documents.destroy', $document));

        Storage::disk('local')->assertMissing('source-documents/test.pdf');
        $this->assertSoftDeleted($document);
    }
}