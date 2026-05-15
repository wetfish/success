<?php

namespace App\Console\Commands;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Services\Extraction\ReviewRecordExtractor;
use Illuminate\Console\Command;

/**
 * Backfills top-level tag/person/link review records for source
 * documents by running ReviewRecordExtractor against them.
 *
 * Two principal uses:
 *
 *   1. After uploading documents under an older extraction pipeline
 *      that didn't produce review records (i.e. documents extracted
 *      before milestone 4.6's review-record derivation landed), this
 *      command walks them and produces the records the new review
 *      flow expects.
 *
 *   2. After the user has added new tags or tag aliases to the
 *      catalog, --force re-runs derivation on documents whose review
 *      records may now resolve to existing catalog entries (their
 *      match_record_id should populate where it previously was null).
 *
 * Usage:
 *   php artisan extraction:backfill-review-records
 *     Iterates every SourceDocument. The service's idempotency guard
 *     skips documents that already have review records.
 *
 *   php artisan extraction:backfill-review-records --document=8
 *     Targets a single document by id. Still subject to the idempotency
 *     guard unless combined with --force.
 *
 *   php artisan extraction:backfill-review-records --force
 *     Deletes pending tag/person/link review records first, then
 *     re-derives. Confirmed/rejected/merged review records are left
 *     alone — user-acted decisions don't get clobbered. Combine with
 *     --document=N for a single-document re-run.
 *
 *   php artisan extraction:backfill-review-records --no-interaction
 *     Skips the destructive-action prompt when --force is set. For
 *     scripted/CI use.
 *
 * Idempotency note: without --force, running this command repeatedly
 * is safe — the service short-circuits any document that already has
 * tag/person/link review records.
 */
class BackfillReviewRecords extends Command
{
    protected $signature = 'extraction:backfill-review-records
        {--document= : Target a single SourceDocument by id}
        {--force : Delete pending tag/person/link review records first, then re-derive}';

    protected $description = 'Backfill tag/person/link review records for existing source documents';

    public function handle(ReviewRecordExtractor $extractor): int
    {
        $documents = $this->resolveDocuments();
        if ($documents === null) {
            return self::FAILURE;
        }

        if ($documents->isEmpty()) {
            $this->info('No source documents to process.');
            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        if ($force && ! $this->confirmForce($documents->count())) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $this->info("Processing {$documents->count()} document(s)" . ($force ? ' (--force)' : '') . '...');
        $this->newLine();

        $totalCreated = 0;
        $totalSkipped = 0;
        $totalDeleted = 0;

        foreach ($documents as $document) {
            $deleted = 0;
            if ($force) {
                $deleted = $this->deletePendingReviewRecords($document);
                $totalDeleted += $deleted;
            }

            $created = $extractor->extract($document);
            $totalCreated += $created;

            // The service returns 0 when idempotency kicks in. Distinguishing
            // "0 because already done" from "0 because nothing to derive" is
            // useful here — re-check the table to decide which message fits.
            if ($created === 0 && $this->documentHasReviewRecords($document)) {
                $totalSkipped++;
                $this->line(sprintf('  doc %d: skipped (already has review records)', $document->id));
            } elseif ($force && $deleted > 0) {
                $this->line(sprintf('  doc %d: deleted %d, created %d', $document->id, $deleted, $created));
            } else {
                $this->line(sprintf('  doc %d: created %d', $document->id, $created));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. Created %d review record(s) across %d document(s)%s%s.',
            $totalCreated,
            $documents->count() - $totalSkipped,
            $totalSkipped > 0 ? sprintf('; skipped %d', $totalSkipped) : '',
            $totalDeleted > 0 ? sprintf('; deleted %d pending', $totalDeleted) : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Build the list of documents to process. Returns null on
     * resolution failure (an invalid --document id) so handle()
     * can report the error and exit non-zero.
     */
    private function resolveDocuments(): mixed
    {
        $documentId = $this->option('document');

        if ($documentId !== null) {
            $document = SourceDocument::find($documentId);
            if (! $document) {
                $this->error("No SourceDocument with id={$documentId}.");
                return null;
            }
            return collect([$document]);
        }

        return SourceDocument::orderBy('id')->get();
    }

    /**
     * Prompt the user before running a destructive --force pass.
     * Bypassed automatically by --no-interaction (a global Symfony
     * Console flag), which is the standard way scripts/CI suppress
     * confirmations.
     */
    private function confirmForce(int $documentCount): bool
    {
        $this->warn(sprintf(
            '--force will delete pending tag/person/link review records for %d document(s).',
            $documentCount,
        ));
        $this->warn('Confirmed/rejected/merged review records are NOT affected.');

        return $this->confirm('Proceed?', false);
    }

    /**
     * Delete pending tag/person/link review records for the given
     * document. Returns the count deleted. Leaves entity drafts
     * (organization/position/project/accomplishment) and any
     * non-pending review records alone.
     */
    private function deletePendingReviewRecords(SourceDocument $document): int
    {
        return ExtractedRecord::query()
            ->where('source_document_id', $document->id)
            ->whereIn('record_type', ['tag', 'person', 'link'])
            ->where('status', 'pending')
            ->delete();
    }

    /**
     * Does this document have any tag/person/link review records?
     * Used to distinguish "service skipped due to idempotency" from
     * "nothing was there to derive in the first place" in the per-doc
     * output line.
     */
    private function documentHasReviewRecords(SourceDocument $document): bool
    {
        return ExtractedRecord::query()
            ->where('source_document_id', $document->id)
            ->whereIn('record_type', ['tag', 'person', 'link'])
            ->exists();
    }
}