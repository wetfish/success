<?php

namespace App\Console\Commands;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Services\AiUsageTracker;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionProvider;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Manual test loop for the extraction pipeline. Prompts for input
 * text, runs it through the configured provider, dumps the resulting
 * drafts as JSON. Used during prompt design iteration before any UI
 * is wired up.
 *
 * Usage:
 *   php artisan test:extraction
 *   php artisan test:extraction --persist
 *
 * Without --persist (default): ephemeral. The command builds a transient
 * SourceDocument, calls extract(), prints the drafts and cost, and
 * exits without writing anything to the database. Use this mode to
 * iterate on prompts and inputs without polluting the catalog.
 *
 * With --persist: saves the SourceDocument, records an AiUsageEvent
 * row with the cost telemetry, and writes each draft to extracted_records
 * as a pending row. Use this mode to do a real extraction run that
 * produces drafts available for review (slice 4) and tracked usage data.
 *
 * Failures during extraction also record an AiUsageEvent with success=false
 * when --persist is set, so failed runs are visible in cost reports.
 */
class TestExtraction extends Command
{
    protected $signature = 'test:extraction {--persist : Persist the source document, drafts, and usage telemetry}';
    protected $description = 'Manually test the AI extraction pipeline with pasted text';

    public function handle(
        ExtractionProvider $provider,
        AiUsageTracker $tracker,
    ): int {
        $this->info("Provider: {$provider->name()}");

        if (! $provider->isAvailable()) {
            $this->error('Provider reports it is not available. Check API key configuration.');
            return self::FAILURE;
        }

        $this->info('Provider is available.');
        $this->newLine();
        $this->line('Paste the text to extract. End with a line containing just "EOF":');
        $this->newLine();

        $lines = [];
        while (($line = fgets(STDIN)) !== false) {
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === 'EOF') {
                break;
            }
            $lines[] = $trimmed;
        }

        $body = implode("\n", $lines);

        if (trim($body) === '') {
            $this->error('No input provided.');
            return self::FAILURE;
        }

        $persist = $this->option('persist');

        // Build a transient SourceDocument — not persisted unless --persist.
        // Uses the real schema column names: 'kind' (not 'source_type'),
        // 'body' (the pasted text content). file_type is set so the
        // provider knows this is plain text and reads from `body`
        // rather than trying to load a file.
        $document = new SourceDocument([
            'title' => 'Test extraction (' . now()->format('Y-m-d H:i:s') . ')',
            'kind' => 'other',
            'file_type' => 'text',
            'body' => $body,
        ]);

        if ($persist) {
            $document->save();
            $this->line("Persisted SourceDocument id={$document->id}");
        }

        $this->newLine();
        $this->info('Estimating tokens...');
        try {
            $estimated = $provider->estimateTokens($document);
            $this->line("Estimated input tokens: {$estimated}");
        } catch (ExtractionException $e) {
            $this->warn("Estimation failed: {$e->getMessage()}");
        }

        $this->newLine();
        $this->info('Calling extract()...');
        $start = microtime(true);

        try {
            $result = $provider->extract($document);
        } catch (ExtractionException $e) {
            $this->error("Extraction failed: {$e->getMessage()}");
            if ($persist) {
                $tracker->recordFailure(
                    provider: $provider->name(),
                    model: 'unknown',
                    operation: 'extract_text',
                    errorMessage: $e->getMessage(),
                    document: $document,
                );
            }
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 2);

        $this->newLine();
        $this->info("Done in {$elapsed}s.");
        $this->line("Model: {$result->model}");
        $this->line("Input tokens: {$result->inputTokens}");
        $this->line("Output tokens: {$result->outputTokens}");
        $this->line("Cost: \${$this->formatCost($result->costCents)}");
        $this->line("Drafts produced: {$result->drafts->count()}");

        if ($persist) {
            $tracker->recordExtraction(
                result: $result,
                provider: $provider->name(),
                operation: 'extract_text',
                document: $document,
            );
            $this->line('Recorded ai_usage_events row.');

            // Save each draft as a pending extracted_record. The status
            // defaults to 'pending' from the migration, so it'll show
            // up in the review queue (slice 4).
            foreach ($result->drafts as $draft) {
                ExtractedRecord::create([
                    'source_document_id' => $document->id,
                    'record_type' => $draft->type,
                    'payload' => $draft->data,
                ]);
            }
            $this->line("Recorded {$result->drafts->count()} extracted_records rows.");
        }

        $this->newLine();
        $this->info('Drafts:');
        $payload = $result->drafts->map(fn ($d) => $d->toArray())->all();
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function formatCost(int $cents): string
    {
        // For costs under a cent, show 4 decimals so we can see fractions of a cent.
        if ($cents < 100) {
            return number_format($cents / 100, 4);
        }
        return Money::format($cents) ?? '0.00';
    }
}