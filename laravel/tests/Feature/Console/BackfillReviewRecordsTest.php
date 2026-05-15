<?php

namespace Tests\Feature\Console;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the extraction:backfill-review-records artisan
 * command.
 *
 * The ReviewRecordExtractor service itself is exhaustively unit-tested
 * (dedup, match pre-compute, idempotency, defensive handling). These
 * tests focus on the command's specific responsibilities: argument
 * parsing, document targeting, the --force destructive path, exit
 * codes, and the side effects of the wiring (records get created,
 * deleted, or left alone as expected).
 */
class BackfillReviewRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function makeDocument(?string $title = null): SourceDocument
    {
        return SourceDocument::create([
            'title' => $title ?? 'Test',
            'kind' => 'other',
            'file_type' => 'text',
            'body' => 'Test body',
        ]);
    }

    private function makeEntityDraft(SourceDocument $doc, string $type, array $payload, string $status = 'pending'): ExtractedRecord
    {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => $type,
            'payload' => $payload,
            'status' => $status,
        ]);
    }

    private function makeReviewRecord(SourceDocument $doc, string $type, array $payload, string $status = 'pending'): ExtractedRecord
    {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => $type,
            'payload' => $payload,
            'status' => $status,
        ]);
    }

    #[Test]
    public function default_run_creates_review_records_for_all_documents(): void
    {
        $doc1 = $this->makeDocument('Doc 1');
        $this->makeEntityDraft($doc1, 'project', [
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);
        $doc2 = $this->makeDocument('Doc 2');
        $this->makeEntityDraft($doc2, 'project', [
            'tags' => [['name' => 'Python', 'category' => 'language']],
        ]);

        $this->artisan('extraction:backfill-review-records')->assertSuccessful();

        $this->assertSame(1, ExtractedRecord::where('source_document_id', $doc1->id)->where('record_type', 'tag')->count());
        $this->assertSame(1, ExtractedRecord::where('source_document_id', $doc2->id)->where('record_type', 'tag')->count());
    }

    #[Test]
    public function default_run_skips_documents_that_already_have_review_records(): void
    {
        // Idempotency: a document that already has review records gets
        // left alone. The "I uploaded one doc, want to derive its
        // review records, but other docs already have theirs" case.
        $alreadyProcessed = $this->makeDocument('Already processed');
        $this->makeEntityDraft($alreadyProcessed, 'project', [
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);
        $existingReview = $this->makeReviewRecord($alreadyProcessed, 'tag', [
            'extracted_name' => 'Postgres',
            'category' => 'tool',
        ]);

        $needsProcessing = $this->makeDocument('Needs processing');
        $this->makeEntityDraft($needsProcessing, 'project', [
            'tags' => [['name' => 'Python', 'category' => 'language']],
        ]);

        $this->artisan('extraction:backfill-review-records')->assertSuccessful();

        // The already-processed doc still has just one tag review record
        // (the pre-existing one — service didn't create a duplicate).
        $this->assertSame(1, ExtractedRecord::where('source_document_id', $alreadyProcessed->id)
            ->where('record_type', 'tag')->count());
        $this->assertSame($existingReview->id, ExtractedRecord::where('source_document_id', $alreadyProcessed->id)
            ->where('record_type', 'tag')->first()->id);

        // The other doc got its review records derived.
        $this->assertSame(1, ExtractedRecord::where('source_document_id', $needsProcessing->id)
            ->where('record_type', 'tag')->count());
    }

    #[Test]
    public function document_option_targets_a_single_document(): void
    {
        $target = $this->makeDocument('Target');
        $this->makeEntityDraft($target, 'project', [
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);
        $other = $this->makeDocument('Other');
        $this->makeEntityDraft($other, 'project', [
            'tags' => [['name' => 'Python', 'category' => 'language']],
        ]);

        $this->artisan('extraction:backfill-review-records', ['--document' => $target->id])
            ->assertSuccessful();

        $this->assertSame(1, ExtractedRecord::where('source_document_id', $target->id)
            ->where('record_type', 'tag')->count());
        // The other doc was untouched.
        $this->assertSame(0, ExtractedRecord::where('source_document_id', $other->id)
            ->where('record_type', 'tag')->count());
    }

    #[Test]
    public function document_option_with_invalid_id_fails_cleanly(): void
    {
        $this->artisan('extraction:backfill-review-records', ['--document' => 99999])
            ->assertFailed();
    }

    #[Test]
    public function command_with_no_documents_in_database_succeeds_with_zero_work(): void
    {
        // Edge case: a fresh database. Command should succeed and
        // print "No source documents to process." without erroring.
        $this->artisan('extraction:backfill-review-records')->assertSuccessful();

        $this->assertSame(0, ExtractedRecord::count());
    }

    // ────────────────────────────────────────────────────────────
    // --force semantics
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function force_deletes_pending_review_records_and_redrives(): void
    {
        // Use case: user added a tag alias in the catalog after
        // initial extraction. The existing pending tag review record
        // has match_record_id = null. --force should delete and
        // re-derive so the new record picks up the match.
        $doc = $this->makeDocument();
        $this->makeEntityDraft($doc, 'project', [
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);

        // Pre-existing pending tag review record with no match.
        $oldReview = $this->makeReviewRecord($doc, 'tag', [
            'extracted_name' => 'Postgres',
            'category' => 'tool',
        ]);
        $this->assertNull($oldReview->match_record_id);

        // Now the catalog gets a matching tag added.
        $newCatalogTag = Tag::create(['name' => 'PostgreSQL', 'category' => 'tool']);
        $newCatalogTag->aliases()->create(['alias' => 'Postgres']);

        // --force re-derives.
        $this->artisan('extraction:backfill-review-records', ['--document' => $doc->id, '--force' => true])
            ->expectsConfirmation('Proceed?', 'yes')
            ->assertSuccessful();

        $reviewRecords = ExtractedRecord::where('source_document_id', $doc->id)
            ->where('record_type', 'tag')->get();
        $this->assertCount(1, $reviewRecords);

        // The old pending record is gone (its id no longer exists).
        $this->assertNull(ExtractedRecord::find($oldReview->id));

        // The new record has the match populated.
        $this->assertSame($newCatalogTag->id, $reviewRecords->first()->match_record_id);
    }

    #[Test]
    public function force_does_not_delete_confirmed_or_rejected_review_records(): void
    {
        // User curation has to survive --force. Only pending records
        // get cleared — confirmed/rejected/merged stay.
        $doc = $this->makeDocument();
        $this->makeEntityDraft($doc, 'project', [
            'tags' => [
                ['name' => 'Pending Tag', 'category' => 'tool'],
                ['name' => 'Confirmed Tag', 'category' => 'tool'],
                ['name' => 'Rejected Tag', 'category' => 'tool'],
            ],
        ]);

        $pendingReview = $this->makeReviewRecord($doc, 'tag',
            ['extracted_name' => 'Pending Tag', 'category' => 'tool'], 'pending');
        $confirmedReview = $this->makeReviewRecord($doc, 'tag',
            ['extracted_name' => 'Confirmed Tag', 'category' => 'tool'], 'confirmed');
        $rejectedReview = $this->makeReviewRecord($doc, 'tag',
            ['extracted_name' => 'Rejected Tag', 'category' => 'tool'], 'rejected');

        $this->artisan('extraction:backfill-review-records', ['--document' => $doc->id, '--force' => true])
            ->expectsConfirmation('Proceed?', 'yes')
            ->assertSuccessful();

        // Pending was deleted; confirmed and rejected survived.
        $this->assertNull(ExtractedRecord::find($pendingReview->id));
        $this->assertNotNull(ExtractedRecord::find($confirmedReview->id));
        $this->assertNotNull(ExtractedRecord::find($rejectedReview->id));
    }

    #[Test]
    public function force_does_not_delete_entity_drafts(): void
    {
        // The defensive guarantee: --force never touches entity drafts.
        // Even when an entity draft has a payload that overlaps with
        // review record types (it doesn't, structurally, but belt-
        // and-suspenders), the record_type='organization' etc. drafts
        // are immune.
        $doc = $this->makeDocument();
        $orgDraft = $this->makeEntityDraft($doc, 'organization', [
            'name' => 'Acme',
            'type' => 'employer',
            'tags' => [['name' => 'B Corp', 'category' => 'concept']],
        ]);
        $projectDraft = $this->makeEntityDraft($doc, 'project', [
            'name' => 'Migration',
            'tags' => [['name' => 'Python', 'category' => 'language']],
        ]);

        $this->artisan('extraction:backfill-review-records', ['--document' => $doc->id, '--force' => true])
            ->expectsConfirmation('Proceed?', 'yes')
            ->assertSuccessful();

        $this->assertNotNull(ExtractedRecord::find($orgDraft->id));
        $this->assertNotNull(ExtractedRecord::find($projectDraft->id));
    }

    #[Test]
    public function force_with_no_confirmation_aborts_without_changes(): void
    {
        // User declines the prompt. No deletions, no creations.
        $doc = $this->makeDocument();
        $this->makeEntityDraft($doc, 'project', [
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);
        $existingReview = $this->makeReviewRecord($doc, 'tag', [
            'extracted_name' => 'Postgres', 'category' => 'tool',
        ]);

        $this->artisan('extraction:backfill-review-records', ['--document' => $doc->id, '--force' => true])
            ->expectsConfirmation('Proceed?', 'no')
            ->assertSuccessful();

        // Pre-existing record still there.
        $this->assertNotNull(ExtractedRecord::find($existingReview->id));
        // Still only one — nothing was created in the abort path.
        $this->assertSame(1, ExtractedRecord::where('source_document_id', $doc->id)
            ->where('record_type', 'tag')->count());
    }

    #[Test]
    public function force_with_no_interaction_skips_the_confirmation_prompt(): void
    {
        // Scripted/CI use: --no-interaction is Symfony's standard
        // way to suppress confirmation prompts. Without it, the
        // command would hang waiting for input in a non-TTY context.
        $doc = $this->makeDocument();
        $this->makeEntityDraft($doc, 'project', [
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);
        $oldReview = $this->makeReviewRecord($doc, 'tag', [
            'extracted_name' => 'Postgres', 'category' => 'tool',
        ]);

        $this->artisan('extraction:backfill-review-records', [
            '--document' => $doc->id,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        // The old record was deleted and a fresh one created.
        $this->assertNull(ExtractedRecord::find($oldReview->id));
        $this->assertSame(1, ExtractedRecord::where('source_document_id', $doc->id)
            ->where('record_type', 'tag')->count());
    }
}