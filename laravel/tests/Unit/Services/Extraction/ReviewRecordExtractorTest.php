<?php

namespace Tests\Unit\Services\Extraction;

use App\Models\ExtractedRecord;
use App\Models\Person;
use App\Models\SourceDocument;
use App\Models\Tag;
use App\Services\Extraction\ReviewRecordExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the ReviewRecordExtractor service.
 *
 * Covers the core behaviors documented on the service:
 *   - Walking pending entity drafts and producing top-level
 *     tag/person/link review records from their nested arrays
 *   - Deduplication (case-insensitive for tags/people, exact-URL
 *     for links) — same entry on multiple drafts → one review record
 *   - Pre-computed match_record_id for tags (by name or alias) and
 *     people (by name); always null for links per MVP policy
 *   - Idempotency — re-running on a document that already has review
 *     records is a no-op
 *   - Defensive handling of malformed nested entries (missing name,
 *     not-an-array, etc.) — skip rather than fail
 *   - Status filter — only walks pending entity drafts, not
 *     confirmed/rejected/merged
 */
class ReviewRecordExtractorTest extends TestCase
{
    use RefreshDatabase;

    private function makeDocument(): SourceDocument
    {
        return SourceDocument::create([
            'title' => 'Test',
            'kind' => 'other',
            'file_type' => 'text',
            'body' => 'Test body',
        ]);
    }

    private function makeDraft(
        SourceDocument $doc,
        string $type,
        array $payload,
        string $status = 'pending',
    ): ExtractedRecord {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => $type,
            'payload' => $payload,
            'status' => $status,
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // No-op cases
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function extract_returns_zero_when_no_pending_entity_drafts_exist(): void
    {
        $doc = $this->makeDocument();

        $count = (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(0, $count);
        $this->assertSame(0, ExtractedRecord::where('source_document_id', $doc->id)->count());
    }

    #[Test]
    public function extract_returns_zero_when_entity_drafts_have_no_nested_arrays(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'organization', ['name' => 'Acme', 'type' => 'employer']);

        $count = (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(0, $count);
    }

    // ────────────────────────────────────────────────────────────
    // Basic creation per type
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function extract_creates_tag_review_record_for_each_unique_nested_tag(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'name' => 'Migration',
            'tags' => [
                ['name' => 'Postgres', 'category' => 'tool'],
                ['name' => 'Python', 'category' => 'language'],
            ],
        ]);

        $count = (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(2, $count);
        $tagRecords = ExtractedRecord::where('record_type', 'tag')->get();
        $this->assertCount(2, $tagRecords);
        $names = $tagRecords->pluck('payload.extracted_name')->sort()->values()->all();
        $this->assertSame(['Postgres', 'Python'], $names);
    }

    #[Test]
    public function extract_creates_person_review_record_for_each_unique_collaborator(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'position', [
            'title' => 'Engineer',
            'collaborators' => [
                ['name' => 'Sarah Chen', 'role' => 'Manager'],
                ['name' => 'Bob Smith', 'role' => 'Peer'],
            ],
        ]);

        $count = (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(2, $count);
        $personRecords = ExtractedRecord::where('record_type', 'person')->get();
        $this->assertCount(2, $personRecords);
        $names = $personRecords->pluck('payload.extracted_name')->sort()->values()->all();
        $this->assertSame(['Bob Smith', 'Sarah Chen'], $names);
    }

    #[Test]
    public function extract_creates_link_review_record_for_each_unique_link(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'name' => 'Migration',
            'links' => [
                ['url' => 'https://github.com/acme/migration', 'type' => 'github', 'title' => 'Source repo'],
                ['url' => 'https://acme.example.com/docs', 'type' => 'documentation'],
            ],
        ]);

        $count = (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(2, $count);
        $linkRecords = ExtractedRecord::where('record_type', 'link')->get();
        $this->assertCount(2, $linkRecords);
    }

    #[Test]
    public function person_review_record_does_not_include_role(): void
    {
        // Roles are per-attachment context, not a property of the
        // person. They stay on the entity draft and don't carry into
        // the person review record.
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'position', [
            'collaborators' => [['name' => 'Sarah Chen', 'role' => 'Manager']],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $personRecord = ExtractedRecord::where('record_type', 'person')->first();
        $this->assertArrayNotHasKey('role', $personRecord->payload);
        $this->assertSame(['extracted_name' => 'Sarah Chen'], $personRecord->payload);
    }

    // ────────────────────────────────────────────────────────────
    // Deduplication
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function tag_appearing_on_multiple_entity_drafts_dedupes_to_one_review_record(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);
        $this->makeDraft($doc, 'project', [
            'name' => 'Migration',
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);

        $count = (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(1, $count);
        $this->assertSame(1, ExtractedRecord::where('record_type', 'tag')->count());
    }

    #[Test]
    public function tag_dedup_is_case_insensitive_and_preserves_first_casing(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);
        $this->makeDraft($doc, 'project', [
            'name' => 'Migration',
            'tags' => [['name' => 'postgres', 'category' => 'tool']],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $tagRecords = ExtractedRecord::where('record_type', 'tag')->get();
        $this->assertCount(1, $tagRecords);
        $this->assertSame('Postgres', $tagRecords->first()->payload['extracted_name']);
    }

    #[Test]
    public function person_dedup_is_case_insensitive(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'position', [
            'collaborators' => [['name' => 'Sarah Chen', 'role' => 'Manager']],
        ]);
        $this->makeDraft($doc, 'project', [
            'collaborators' => [['name' => 'sarah chen', 'role' => 'Reviewer']],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(1, ExtractedRecord::where('record_type', 'person')->count());
    }

    #[Test]
    public function link_dedup_uses_exact_url_match(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'links' => [
                ['url' => 'https://github.com/acme/migration', 'type' => 'github'],
                ['url' => 'https://github.com/acme/migration', 'type' => 'github'],
            ],
        ]);
        $this->makeDraft($doc, 'organization', [
            'name' => 'Acme',
            'links' => [
                ['url' => 'https://github.com/acme/migration', 'type' => 'github'],
            ],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(1, ExtractedRecord::where('record_type', 'link')->count());
    }

    // ────────────────────────────────────────────────────────────
    // Match pre-computation
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function tag_review_record_has_match_record_id_when_tag_exists_by_name(): void
    {
        $existing = Tag::create(['name' => 'Python', 'category' => 'language']);
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'tags' => [['name' => 'Python', 'category' => 'language']],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $record = ExtractedRecord::where('record_type', 'tag')->first();
        $this->assertSame('tag', $record->match_record_type);
        $this->assertSame($existing->id, $record->match_record_id);
    }

    #[Test]
    public function tag_review_record_has_match_record_id_when_tag_exists_by_alias(): void
    {
        $existing = Tag::create(['name' => 'PostgreSQL', 'category' => 'tool']);
        $existing->aliases()->create(['alias' => 'postgres']);
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $record = ExtractedRecord::where('record_type', 'tag')->first();
        $this->assertSame($existing->id, $record->match_record_id);
    }

    #[Test]
    public function tag_review_record_has_null_match_record_id_when_tag_does_not_exist(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'tags' => [['name' => 'NewlyMentioned', 'category' => 'tool']],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $record = ExtractedRecord::where('record_type', 'tag')->first();
        $this->assertNull($record->match_record_id);
        $this->assertNull($record->match_record_type);
    }

    #[Test]
    public function person_review_record_has_match_record_id_when_person_exists(): void
    {
        $existing = Person::create(['name' => 'Sarah Chen']);
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'position', [
            'collaborators' => [['name' => 'Sarah Chen', 'role' => 'Manager']],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $record = ExtractedRecord::where('record_type', 'person')->first();
        $this->assertSame('person', $record->match_record_type);
        $this->assertSame($existing->id, $record->match_record_id);
    }

    #[Test]
    public function person_review_record_has_null_match_record_id_when_person_does_not_exist(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'position', [
            'collaborators' => [['name' => 'New Person', 'role' => 'Manager']],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $record = ExtractedRecord::where('record_type', 'person')->first();
        $this->assertNull($record->match_record_id);
    }

    #[Test]
    public function link_review_record_always_has_null_match_record_id_for_mvp(): void
    {
        // Even if a Link with the same URL exists in the catalog, MVP
        // policy is to render all link review records as actionable.
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'links' => [['url' => 'https://github.com/acme/migration', 'type' => 'github']],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $record = ExtractedRecord::where('record_type', 'link')->first();
        $this->assertNull($record->match_record_id);
    }

    // ────────────────────────────────────────────────────────────
    // Idempotency
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function running_extract_twice_does_not_create_duplicate_review_records(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
            'collaborators' => [['name' => 'Sarah Chen']],
            'links' => [['url' => 'https://example.com', 'type' => 'website']],
        ]);

        $extractor = new ReviewRecordExtractor();
        $first = $extractor->extract($doc);
        $second = $extractor->extract($doc);

        $this->assertSame(3, $first);
        $this->assertSame(0, $second); // Idempotency guard returns 0.
        $this->assertSame(3, ExtractedRecord::whereIn('record_type', ['tag', 'person', 'link'])->count());
    }

    // ────────────────────────────────────────────────────────────
    // Status filter
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function confirmed_entity_drafts_are_not_walked(): void
    {
        // Confirmed drafts have already materialized their nested data
        // into the catalog — re-deriving review records from them
        // would be redundant.
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ], status: 'confirmed');

        $count = (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(0, $count);
    }

    #[Test]
    public function rejected_entity_drafts_are_not_walked(): void
    {
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ], status: 'rejected');

        $count = (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(0, $count);
    }

    // ────────────────────────────────────────────────────────────
    // Defensive handling
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function malformed_nested_entries_are_skipped(): void
    {
        // Defensive: AI deviations shouldn't crash the derivation.
        // Entries without name (or url for links) are skipped silently.
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'name' => 'Migration',
            'tags' => [
                ['name' => 'Valid', 'category' => 'tool'],
                ['category' => 'tool'],  // no name — skip
                'not an array',          // not-array — skip
                ['name' => '   '],       // whitespace name — skip
                ['name' => 'Also Valid'],
            ],
            'links' => [
                ['type' => 'github'],    // no url — skip
                ['url' => 'https://valid.example.com'],
                ['url' => ''],           // empty url — skip
            ],
        ]);

        $count = (new ReviewRecordExtractor())->extract($doc);

        $this->assertSame(3, $count); // 2 tags + 1 link
        $this->assertSame(2, ExtractedRecord::where('record_type', 'tag')->count());
        $this->assertSame(1, ExtractedRecord::where('record_type', 'link')->count());
    }

    #[Test]
    public function tag_with_no_category_creates_record_with_no_category_in_payload(): void
    {
        // AI may emit a tag without a category. The review record's
        // payload simply omits the category key — the chunk-4 UI will
        // prompt the user to pick one at confirmation time.
        $doc = $this->makeDocument();
        $this->makeDraft($doc, 'project', [
            'tags' => [['name' => 'Mysterious Thing']],
        ]);

        (new ReviewRecordExtractor())->extract($doc);

        $record = ExtractedRecord::where('record_type', 'tag')->first();
        $this->assertArrayNotHasKey('category', $record->payload);
        $this->assertSame('Mysterious Thing', $record->payload['extracted_name']);
    }
}