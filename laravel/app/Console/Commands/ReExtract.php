<?php

namespace App\Console\Commands;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Services\AiUsageTracker;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionProvider;
use App\Services\Extraction\ReviewRecordExtractor;
use Illuminate\Console\Command;

/**
 * Re-extract records from an existing source document. Deletes all
 * extracted_records for the document, re-runs the AI extraction
 * pipeline, and re-derives review records.
 *
 * This is a destructive operation: all previous entity drafts
 * (organizations, positions, projects, accomplishments) and review
 * records (tags, people, links) for the document are wiped. Review
 * decisions (accept/reject/alias) are lost. The document starts
 * fresh in the review wizard.
 *
 * Catalog records created during previous review sessions (tags,
 * people, organizations, etc.) are NOT deleted — they persist in
 * the catalog independently. AI usage events are also preserved
 * for cost tracking.
 *
 * Usage:
 *   php artisan extraction:re-extract --document=8
 *     Re-extracts a single document. Prompts for confirmation.
 *
 *   php artisan extraction:re-extract --document=8 --no-interaction
 *     Skips the confirmation prompt. For scripted use.
 */
class ReExtract extends Command
{
    protected $signature = 'extraction:re-extract
        {--document= : Target SourceDocument by id (required)}';

    protected $description = 'Delete extracted records and re-run AI extraction for a source document';

    public function handle(
        ExtractionProvider $provider,
        AiUsageTracker $tracker,
        ReviewRecordExtractor $reviewExtractor,
    ): int {
        $documentId = $this->option('document');
        if ($documentId === null) {
            $this->error('The --document option is required. Usage: php artisan extraction:re-extract --document=8');
            return self::FAILURE;
        }

        $document = SourceDocument::find($documentId);
        if (! $document) {
            $this->error("No SourceDocument with id={$documentId}.");
            return self::FAILURE;
        }

        if (! $provider->isAvailable()) {
            $this->error('Extraction provider is not available. Check API key configuration.');
            return self::FAILURE;
        }

        // Count existing records so the user knows what they're about to lose.
        $existingCount = ExtractedRecord::where('source_document_id', $document->id)->count();

        $this->info("Document: {$document->title} (id={$document->id})");
        $this->line("Existing extracted records: {$existingCount}");
        $this->newLine();

        if ($existingCount > 0) {
            $this->warn('This will delete ALL extracted records for this document.');
            $this->warn('Review decisions (accept/reject/alias) will be lost.');
            $this->warn('Catalog records already created are NOT affected.');

            if ($this->input->isInteractive() && ! $this->confirm('Proceed with re-extraction?', false)) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        // Step 1: Delete all extracted records for this document.
        if ($existingCount > 0) {
            $deleted = ExtractedRecord::where('source_document_id', $document->id)->delete();
            $this->line("Deleted {$deleted} extracted record(s).");
        }

        // Step 2: Re-run AI extraction.
        $this->info('Running extraction...');
        $start = microtime(true);

        try {
            $result = $provider->extract($document);
        } catch (ExtractionException $e) {
            $this->error("Extraction failed: {$e->getMessage()}");
            $tracker->recordFailure(
                provider: $provider->name(),
                model: 'unknown',
                operation: 'extract_text',
                errorMessage: $e->getMessage(),
                document: $document,
            );
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 2);

        $tracker->recordExtraction(
            result: $result,
            provider: $provider->name(),
            operation: 'extract_text',
            document: $document,
        );

        // Step 3: Persist entity drafts.
        foreach ($result->drafts as $draft) {
            ExtractedRecord::create([
                'source_document_id' => $document->id,
                'record_type' => $draft->type,
                'payload' => $draft->data,
            ]);
        }

        // Step 4: Derive review records.
        $reviewCount = $reviewExtractor->extract($document);

        $this->newLine();
        $this->info("Done in {$elapsed}s.");
        $this->line("Model: {$result->model}");
        $this->line("Input tokens: {$result->inputTokens}");
        $this->line("Output tokens: {$result->outputTokens}");
        $this->line("Drafts produced: {$result->drafts->count()}");
        $this->line("Review records derived: {$reviewCount}");

        return self::SUCCESS;
    }
}