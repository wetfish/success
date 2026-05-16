<?php

namespace Tests\Feature;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Models\Tag;
use App\Models\TagAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the tag review wizard step controller.
 *
 * Covers:
 *   - The show page (rendering, grouping, mentions, empty redirect)
 *   - accept / reject / alias action endpoints (state transitions,
 *     idempotency, error responses, JSON shape)
 *   - Cross-document scoping (record from one doc can't be acted on
 *     via another doc's URL)
 *
 * The middleware behavior is covered by RequireTagReviewCompleteTest.
 */
class TagReviewControllerTest extends TestCase
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

    private function makeTagReviewRecord(
        SourceDocument $doc,
        string $extractedName,
        ?string $category = null,
        string $status = 'pending',
        ?int $matchRecordId = null,
        bool $catalogCreatedByReview = false,
    ): ExtractedRecord {
        $payload = ['extracted_name' => $extractedName];
        if ($category !== null) {
            $payload['category'] = $category;
        }
        if ($catalogCreatedByReview) {
            $payload['catalog_tag_created_by_review'] = true;
        }
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => 'tag',
            'payload' => $payload,
            'status' => $status,
            'match_record_type' => $matchRecordId ? 'tag' : null,
            'match_record_id' => $matchRecordId,
        ]);
    }

    private function makeEntityDraft(SourceDocument $doc, string $type, array $payload): ExtractedRecord
    {
        return ExtractedRecord::create([
            'source_document_id' => $doc->id,
            'record_type' => $type,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // show: page rendering
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function show_renders_all_tag_review_records_grouped_by_category(): void
    {
        $doc = $this->makeDocument();
        $this->makeTagReviewRecord($doc, 'Python', 'language');
        $this->makeTagReviewRecord($doc, 'JavaScript', 'language');
        $this->makeTagReviewRecord($doc, 'Postgres', 'tool');

        $response = $this->get(route('source-documents.review.tags.show', $doc));

        $response->assertOk();
        $response->assertSee('Python');
        $response->assertSee('JavaScript');
        $response->assertSee('Postgres');
    }

    #[Test]
    public function show_includes_already_decided_records_alongside_pending(): void
    {
        // Q2 from the design conversation: page surfaces the full audit
        // trail (everything extracted) regardless of decision state.
        $doc = $this->makeDocument();
        $existingTag = Tag::create(['name' => 'Python', 'category' => 'language']);
        $this->makeTagReviewRecord($doc, 'Postgres', 'tool', 'pending');
        $this->makeTagReviewRecord($doc, 'Python', 'language', 'confirmed', $existingTag->id);
        $this->makeTagReviewRecord($doc, 'Rejected Tag', 'tool', 'rejected');

        $response = $this->get(route('source-documents.review.tags.show', $doc));

        $response->assertOk();
        $response->assertSee('Postgres');
        $response->assertSee('Python');
        $response->assertSee('Rejected Tag');
    }

    #[Test]
    public function show_redirects_when_document_has_no_tag_review_records(): void
    {
        // The empty-list edge case: a document with zero extracted tags
        // shouldn't render a blank tag review page. The controller
        // redirects to the review index, which then advances to the
        // next wizard step.
        $doc = $this->makeDocument();

        $this->get(route('source-documents.review.tags.show', $doc))
            ->assertRedirect(route('source-documents.review.index', $doc));
    }

    #[Test]
    public function show_includes_mentions_context_from_entity_drafts(): void
    {
        $doc = $this->makeDocument();
        $this->makeTagReviewRecord($doc, 'Postgres', 'tool');
        // An entity draft that mentions Postgres in its nested tags.
        $this->makeEntityDraft($doc, 'project', [
            'name' => 'User DB Migration',
            'tags' => [['name' => 'Postgres', 'category' => 'tool']],
        ]);

        $response = $this->get(route('source-documents.review.tags.show', $doc));

        $response->assertOk();
        // The mentions line should reference the project's name.
        $response->assertSee('User DB Migration');
    }

    #[Test]
    public function show_renders_records_with_uncategorized_when_ai_category_invalid(): void
    {
        // Defensive: if the AI emitted a category outside Tag::CATEGORIES,
        // the record still surfaces — under an Uncategorized heading.
        // The page shouldn't lose information just because the AI
        // emitted something off-enum.
        $doc = $this->makeDocument();
        $this->makeTagReviewRecord($doc, 'MysteryTag', 'not-a-real-category');

        $response = $this->get(route('source-documents.review.tags.show', $doc));

        $response->assertOk();
        $response->assertSee('MysteryTag');
        $response->assertSee('Uncategorized');
    }

    // ────────────────────────────────────────────────────────────
    // accept action
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function accept_creates_catalog_tag_and_confirms_record(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeTagReviewRecord($doc, 'Kubernetes', 'tool');

        $response = $this->postJson(route('source-documents.review.tags.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]));

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $response->assertJsonStructure(['ok', 'catalog_tag_name']);

        $record->refresh();
        $this->assertSame('confirmed', $record->status);
        $this->assertSame('tag', $record->match_record_type);
        $this->assertNotNull($record->match_record_id);
        $this->assertTrue($record->payload['catalog_tag_created_by_review']);

        $newTag = Tag::find($record->match_record_id);
        $this->assertSame('Kubernetes', $newTag->name);
        $this->assertSame('tool', $newTag->category);
    }

    #[Test]
    public function accept_attaches_to_existing_catalog_tag_without_creating_duplicate(): void
    {
        // If the catalog already has a tag matching the extracted name
        // (case-insensitively), accept attaches to it rather than
        // creating a duplicate. The catalog_tag_created_by_review flag
        // is NOT set in this case — we didn't create the tag.
        $doc = $this->makeDocument();
        $existing = Tag::create(['name' => 'Python', 'category' => 'language']);
        $record = $this->makeTagReviewRecord($doc, 'Python', 'language');

        $this->postJson(route('source-documents.review.tags.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $record->refresh();
        $this->assertSame($existing->id, $record->match_record_id);
        $this->assertSame(1, Tag::count());
        $this->assertArrayNotHasKey('catalog_tag_created_by_review', $record->payload);
    }

    #[Test]
    public function accept_drops_invalid_category_to_null_when_creating(): void
    {
        // If the AI emitted a category outside Tag::CATEGORIES, the
        // new catalog tag is created with null category rather than
        // failing. Defense in depth.
        $doc = $this->makeDocument();
        $record = $this->makeTagReviewRecord($doc, 'NewTag', 'not-a-real-category');

        $this->postJson(route('source-documents.review.tags.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $record->refresh();
        $newTag = Tag::find($record->match_record_id);
        $this->assertNull($newTag->category);
    }

    #[Test]
    public function accept_returns_catalog_tag_name_for_js_display(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeTagReviewRecord($doc, 'Kubernetes', 'tool');

        $response = $this->postJson(route('source-documents.review.tags.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]));

        $response->assertJson(['catalog_tag_name' => 'Kubernetes']);
    }

    #[Test]
    public function accept_transitions_from_rejected_to_confirmed(): void
    {
        // The user previously rejected, now changes their mind. Accept
        // should work from the rejected state.
        $doc = $this->makeDocument();
        $record = $this->makeTagReviewRecord($doc, 'Postgres', 'tool', 'rejected');

        $this->postJson(route('source-documents.review.tags.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $record->refresh();
        $this->assertSame('confirmed', $record->status);
        $this->assertNotNull($record->match_record_id);
    }

    #[Test]
    public function accept_after_alias_deletes_alias_row_and_creates_new_catalog_tag(): void
    {
        // User aliased "Postgres" to "PostgreSQL", then changed their
        // mind to accept Postgres as its own tag. Accept's
        // revertPriorDecision deletes the alias row; then the
        // find-by-name doesn't find Postgres (only PostgreSQL exists),
        // so accept creates a new Postgres tag.
        $doc = $this->makeDocument();
        $postgresql = Tag::create(['name' => 'PostgreSQL', 'category' => 'tool']);
        $record = $this->makeTagReviewRecord($doc, 'Postgres', 'tool', 'merged', $postgresql->id);
        TagAlias::create(['alias' => 'Postgres', 'tag_id' => $postgresql->id]);

        $this->postJson(route('source-documents.review.tags.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $record->refresh();
        $this->assertSame('confirmed', $record->status);
        // New Postgres tag created.
        $newTag = Tag::find($record->match_record_id);
        $this->assertSame('Postgres', $newTag->name);
        $this->assertNotSame($postgresql->id, $newTag->id);
        // Old alias row deleted.
        $this->assertSame(0, TagAlias::where('alias', 'Postgres')->count());
    }

    #[Test]
    public function accept_404s_when_record_belongs_to_different_document(): void
    {
        $doc = $this->makeDocument('Doc 1');
        $otherDoc = $this->makeDocument('Doc 2');
        $record = $this->makeTagReviewRecord($otherDoc, 'Postgres', 'tool');

        $response = $this->postJson(route('source-documents.review.tags.accept', [
            'sourceDocument' => $doc, 'record' => $record,
        ]));

        $response->assertStatus(404);
        $response->assertJsonStructure(['error']);
    }

    #[Test]
    public function accept_404s_when_record_is_not_a_tag(): void
    {
        $doc = $this->makeDocument();
        $orgRecord = $this->makeEntityDraft($doc, 'organization', ['name' => 'Acme']);

        $response = $this->postJson(route('source-documents.review.tags.accept', [
            'sourceDocument' => $doc, 'record' => $orgRecord,
        ]));

        $response->assertStatus(404);
    }

    // ────────────────────────────────────────────────────────────
    // reject action
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function reject_marks_pending_record_as_rejected(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeTagReviewRecord($doc, 'Postgres', 'tool');

        $this->postJson(route('source-documents.review.tags.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $record->refresh();
        $this->assertSame('rejected', $record->status);
        $this->assertNull($record->match_record_id);
        $this->assertNull($record->match_record_type);
    }

    #[Test]
    public function reject_deletes_catalog_tag_when_review_created_it(): void
    {
        $doc = $this->makeDocument();
        $createdTag = Tag::create(['name' => 'Postgres', 'category' => 'tool']);
        $record = $this->makeTagReviewRecord(
            $doc, 'Postgres', 'tool', 'confirmed', $createdTag->id, catalogCreatedByReview: true,
        );

        $this->postJson(route('source-documents.review.tags.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $this->assertNull(Tag::find($createdTag->id));
    }

    #[Test]
    public function reject_leaves_catalog_tag_when_review_did_not_create_it(): void
    {
        // Pre-existing tag (e.g., the user manually added it via the
        // catalog UI, or it was matched at derivation time). Reject
        // shouldn't delete it — we only revert review's own mutations.
        $doc = $this->makeDocument();
        $existingTag = Tag::create(['name' => 'Python', 'category' => 'language']);
        $record = $this->makeTagReviewRecord(
            $doc, 'Python', 'language', 'confirmed', $existingTag->id, catalogCreatedByReview: false,
        );

        $this->postJson(route('source-documents.review.tags.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $this->assertNotNull(Tag::find($existingTag->id));
    }

    #[Test]
    public function reject_deletes_alias_row_when_record_was_aliased(): void
    {
        $doc = $this->makeDocument();
        $target = Tag::create(['name' => 'PostgreSQL', 'category' => 'tool']);
        $record = $this->makeTagReviewRecord($doc, 'Postgres', 'tool', 'merged', $target->id);
        TagAlias::create(['alias' => 'Postgres', 'tag_id' => $target->id]);

        $this->postJson(route('source-documents.review.tags.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertOk();

        $this->assertSame(0, TagAlias::where('alias', 'Postgres')->count());
        // Target tag stays — it existed before this review record's decision.
        $this->assertNotNull(Tag::find($target->id));
    }

    #[Test]
    public function reject_404s_for_cross_document_record(): void
    {
        $doc = $this->makeDocument();
        $otherDoc = $this->makeDocument('Other');
        $record = $this->makeTagReviewRecord($otherDoc, 'Postgres', 'tool');

        $this->postJson(route('source-documents.review.tags.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]))->assertStatus(404);
    }

    // ────────────────────────────────────────────────────────────
    // alias action
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function alias_creates_alias_row_and_merges_record(): void
    {
        $doc = $this->makeDocument();
        $target = Tag::create(['name' => 'PostgreSQL', 'category' => 'tool']);
        $record = $this->makeTagReviewRecord($doc, 'Postgres', 'tool');

        $this->postJson(route('source-documents.review.tags.alias', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['target_tag_id' => $target->id])->assertOk();

        $record->refresh();
        $this->assertSame('merged', $record->status);
        $this->assertSame($target->id, $record->match_record_id);
        $this->assertSame(1, TagAlias::where('alias', 'Postgres')->where('tag_id', $target->id)->count());
    }

    #[Test]
    public function alias_after_accept_deletes_previously_created_catalog_tag(): void
    {
        $doc = $this->makeDocument();
        $oldTag = Tag::create(['name' => 'Postgres', 'category' => 'tool']);
        $newTarget = Tag::create(['name' => 'PostgreSQL', 'category' => 'tool']);
        $record = $this->makeTagReviewRecord(
            $doc, 'Postgres', 'tool', 'confirmed', $oldTag->id, catalogCreatedByReview: true,
        );

        $this->postJson(route('source-documents.review.tags.alias', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['target_tag_id' => $newTarget->id])->assertOk();

        $this->assertNull(Tag::find($oldTag->id));
        $this->assertNotNull(Tag::find($newTarget->id));
        $record->refresh();
        $this->assertSame('merged', $record->status);
        $this->assertSame($newTarget->id, $record->match_record_id);
    }

    #[Test]
    public function alias_422_when_target_tag_id_missing(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeTagReviewRecord($doc, 'Postgres', 'tool');

        $response = $this->postJson(route('source-documents.review.tags.alias', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }

    #[Test]
    public function alias_422_when_target_tag_does_not_exist(): void
    {
        $doc = $this->makeDocument();
        $record = $this->makeTagReviewRecord($doc, 'Postgres', 'tool');

        $response = $this->postJson(route('source-documents.review.tags.alias', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['target_tag_id' => 99999]);

        $response->assertStatus(422);
    }

    #[Test]
    public function alias_422_when_extracted_name_is_already_alias_of_different_tag(): void
    {
        // Global unique constraint on tag_aliases.alias. The controller
        // pre-checks rather than letting the DB constraint surface as
        // a 500.
        $doc = $this->makeDocument();
        $otherTarget = Tag::create(['name' => 'Other', 'category' => 'tool']);
        TagAlias::create(['alias' => 'Postgres', 'tag_id' => $otherTarget->id]);
        $target = Tag::create(['name' => 'PostgreSQL', 'category' => 'tool']);
        $record = $this->makeTagReviewRecord($doc, 'Postgres', 'tool');

        $response = $this->postJson(route('source-documents.review.tags.alias', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['target_tag_id' => $target->id]);

        $response->assertStatus(422);
    }

    #[Test]
    public function alias_422_when_aliasing_to_self_created_catalog_tag(): void
    {
        // Self-alias guard: if a previous accept created a catalog tag
        // and the user picks that same tag as the alias target, the
        // operation would delete the target before trying to alias to
        // it. The controller rejects this up-front.
        $doc = $this->makeDocument();
        $selfCreated = Tag::create(['name' => 'Postgres', 'category' => 'tool']);
        $record = $this->makeTagReviewRecord(
            $doc, 'Postgres', 'tool', 'confirmed', $selfCreated->id, catalogCreatedByReview: true,
        );

        $response = $this->postJson(route('source-documents.review.tags.alias', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['target_tag_id' => $selfCreated->id]);

        $response->assertStatus(422);
    }

    #[Test]
    public function alias_404s_for_cross_document_record(): void
    {
        $doc = $this->makeDocument();
        $otherDoc = $this->makeDocument('Other');
        $target = Tag::create(['name' => 'Target', 'category' => 'tool']);
        $record = $this->makeTagReviewRecord($otherDoc, 'Postgres', 'tool');

        $this->postJson(route('source-documents.review.tags.alias', [
            'sourceDocument' => $doc, 'record' => $record,
        ]), ['target_tag_id' => $target->id])->assertStatus(404);
    }

    // ────────────────────────────────────────────────────────────
    // JSON response shape contract
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function action_responses_always_return_json(): void
    {
        // The JS client expects JSON always. Even 4xx / 5xx must
        // come back as {error: '...'} JSON, not HTML error pages.
        $doc = $this->makeDocument();
        $otherDoc = $this->makeDocument('Other');
        $record = $this->makeTagReviewRecord($otherDoc, 'Postgres', 'tool');

        $response = $this->postJson(route('source-documents.review.tags.reject', [
            'sourceDocument' => $doc, 'record' => $record,
        ]));

        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonStructure(['error']);
    }
}