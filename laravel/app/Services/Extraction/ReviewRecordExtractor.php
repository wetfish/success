<?php

namespace App\Services\Extraction;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Services\Resolution\PersonResolver;
use App\Services\Resolution\TagResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Walks a source document's pending entity drafts and produces top-level
 * review records (record_type = 'tag' | 'person' | 'link') from the
 * nested arrays on those drafts.
 *
 * The AI emits tags, people, and links only as nested arrays on entity
 * drafts (see ClaudeExtractionProvider's prompt). Those nested arrays
 * are the source of truth for materialization at confirm time — but
 * the user needs a flat, deduplicated review surface to make decisions
 * about each unique tag/person/URL before the entity confirmations
 * happen. That's what this service produces.
 *
 * For each unique entry (case-insensitive by name for tags and people,
 * exact-URL for links), it creates one ExtractedRecord row in 'pending'
 * status. For tags and people, the service uses TagResolver::preview
 * and PersonResolver::preview to pre-compute whether the name already
 * exists in the catalog — if so, match_record_type and match_record_id
 * are set at creation time, giving the review UI an "already exists vs
 * actionable" distinction without having to query at render time.
 *
 * Links don't get a pre-computed match for MVP — the same URL can
 * legitimately live on multiple parents (one Link row per parent), so
 * "does this URL exist anywhere?" isn't a useful disambiguator. Every
 * link review record renders as actionable.
 *
 * Idempotency: if any tag/person/link review records already exist for
 * the document, the service is a no-op. The intent is "derivation runs
 * once after extraction, doesn't re-run during review." A future
 * re-extraction flow would clear the existing review records first.
 */
class ReviewRecordExtractor
{
    private TagResolver $tagResolver;
    private PersonResolver $personResolver;

    public function __construct(
        ?TagResolver $tagResolver = null,
        ?PersonResolver $personResolver = null,
    ) {
        $this->tagResolver = $tagResolver ?? new TagResolver();
        $this->personResolver = $personResolver ?? new PersonResolver();
    }

    /**
     * Walk the document's pending entity drafts and create review
     * records for their unique nested tags/people/links. Returns the
     * total count of review records created. Returns 0 if review
     * records already exist for this document (idempotency guard).
     */
    public function extract(SourceDocument $document): int
    {
        if ($this->alreadyExtracted($document)) {
            return 0;
        }

        return DB::transaction(function () use ($document) {
            $entityDrafts = $this->fetchPendingEntityDrafts($document);

            $tagEntries = $this->collectUniqueTagEntries($entityDrafts);
            $personEntries = $this->collectUniquePersonEntries($entityDrafts);
            $linkEntries = $this->collectUniqueLinkEntries($entityDrafts);

            $created = 0;
            $created += $this->createTagReviewRecords($document, $tagEntries);
            $created += $this->createPersonReviewRecords($document, $personEntries);
            $created += $this->createLinkReviewRecords($document, $linkEntries);

            return $created;
        });
    }

    /**
     * The idempotency guard: if any tag/person/link review records
     * already exist for this document, derivation has already run.
     */
    private function alreadyExtracted(SourceDocument $document): bool
    {
        return ExtractedRecord::query()
            ->where('source_document_id', $document->id)
            ->whereIn('record_type', ['tag', 'person', 'link'])
            ->exists();
    }

    /**
     * Pending entity drafts only. Confirmed drafts have already
     * materialized their nested data into the catalog, so they'd
     * produce stale review records. Rejected/merged drafts don't
     * contribute either — only pending drafts represent work the
     * user hasn't acted on yet.
     */
    private function fetchPendingEntityDrafts(SourceDocument $document): Collection
    {
        return ExtractedRecord::query()
            ->where('source_document_id', $document->id)
            ->where('status', 'pending')
            ->whereIn('record_type', ['organization', 'position', 'project', 'accomplishment'])
            ->get();
    }

    /**
     * Walk all entity drafts' `tags` arrays and produce a deduplicated
     * map keyed by case-insensitive name. First occurrence wins for
     * category — if the AI emits the same tag with different categories
     * on different drafts, we use the first one's category and ignore
     * the rest. This is rare in practice and the user can correct it
     * during review.
     *
     * Returns ['lowername' => ['name' => 'Original Case', 'category' => '...']].
     */
    private function collectUniqueTagEntries(Collection $drafts): array
    {
        $byKey = [];
        foreach ($drafts as $draft) {
            $entries = $draft->payload['tags'] ?? null;
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $name = $entry['name'] ?? null;
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }

                $key = strtolower(trim($name));
                if (isset($byKey[$key])) {
                    continue;
                }

                $byKey[$key] = [
                    'name' => trim($name),
                    'category' => isset($entry['category']) && is_string($entry['category']) && trim($entry['category']) !== ''
                        ? trim($entry['category'])
                        : null,
                ];
            }
        }
        return $byKey;
    }

    /**
     * Walk entity drafts' `collaborators` arrays and dedupe by
     * case-insensitive name. Per-collaborator roles aren't pulled
     * into the person review record — roles are per-attachment
     * (Sarah's "Manager" on position A is independent from her
     * "Peer" on position B), so they belong on the entity drafts,
     * not on the person review record.
     */
    private function collectUniquePersonEntries(Collection $drafts): array
    {
        $byKey = [];
        foreach ($drafts as $draft) {
            $entries = $draft->payload['collaborators'] ?? null;
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $name = $entry['name'] ?? null;
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }

                $key = strtolower(trim($name));
                if (isset($byKey[$key])) {
                    continue;
                }

                $byKey[$key] = [
                    'name' => trim($name),
                ];
            }
        }
        return $byKey;
    }

    /**
     * Walk entity drafts' `links` arrays and dedupe by exact URL.
     * Case matters in URLs — Github.com and github.com are different
     * keys even if servers typically normalize them. The AI's emitted
     * type, title, description, etc. from the first occurrence carry
     * into the review record payload.
     */
    private function collectUniqueLinkEntries(Collection $drafts): array
    {
        $byKey = [];
        foreach ($drafts as $draft) {
            $entries = $draft->payload['links'] ?? null;
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $url = $entry['url'] ?? null;
                if (! is_string($url) || trim($url) === '') {
                    continue;
                }

                $key = trim($url);
                if (isset($byKey[$key])) {
                    continue;
                }

                $byKey[$key] = array_filter([
                    'url' => trim($url),
                    'type' => isset($entry['type']) && is_string($entry['type']) && trim($entry['type']) !== ''
                        ? trim($entry['type']) : null,
                    'title' => isset($entry['title']) && is_string($entry['title']) && trim($entry['title']) !== ''
                        ? trim($entry['title']) : null,
                    'description' => isset($entry['description']) && is_string($entry['description']) && trim($entry['description']) !== ''
                        ? trim($entry['description']) : null,
                    'is_personal_appearance' => isset($entry['is_personal_appearance']) && is_bool($entry['is_personal_appearance'])
                        ? $entry['is_personal_appearance'] : null,
                    'date' => isset($entry['date']) && is_string($entry['date']) && trim($entry['date']) !== ''
                        ? trim($entry['date']) : null,
                ], fn ($v) => $v !== null);
            }
        }
        return $byKey;
    }

    /**
     * Create a tag review record per unique entry. Uses TagResolver's
     * preview() to pre-compute whether a matching catalog tag (or
     * alias) already exists. If so, match_record_type and
     * match_record_id are set so the review UI can render the
     * "already exists" state without a render-time lookup.
     *
     * The AI's emitted category is preserved verbatim in the payload,
     * even if it isn't in the closed Tag::CATEGORIES enum — the chunk-4
     * review UI will validate and normalize at confirmation time, and
     * preserving the AI's value gives the UI more information to work
     * with (e.g., displaying "the AI suggested X, please pick a valid
     * category").
     */
    private function createTagReviewRecords(SourceDocument $document, array $entries): int
    {
        foreach ($entries as $entry) {
            $preview = $this->tagResolver->preview($entry['name']);

            $payload = ['extracted_name' => $entry['name']];
            if ($entry['category'] !== null) {
                $payload['category'] = $entry['category'];
            }

            ExtractedRecord::create([
                'source_document_id' => $document->id,
                'record_type' => 'tag',
                'payload' => $payload,
                'status' => 'pending',
                'match_record_type' => $preview['tag'] ? 'tag' : null,
                'match_record_id' => $preview['tag']?->id,
            ]);
        }
        return count($entries);
    }

    /**
     * Create a person review record per unique entry. Uses
     * PersonResolver's preview() for pre-compute. People have no
     * alias system (yet), so preview returns either 'existing' or
     * 'new' — no third state.
     */
    private function createPersonReviewRecords(SourceDocument $document, array $entries): int
    {
        foreach ($entries as $entry) {
            $preview = $this->personResolver->preview($entry['name']);

            ExtractedRecord::create([
                'source_document_id' => $document->id,
                'record_type' => 'person',
                'payload' => ['extracted_name' => $entry['name']],
                'status' => 'pending',
                'match_record_type' => $preview['person'] ? 'person' : null,
                'match_record_id' => $preview['person']?->id,
            ]);
        }
        return count($entries);
    }

    /**
     * Create a link review record per unique URL. No pre-computed
     * match for MVP — see class docblock for the reasoning.
     */
    private function createLinkReviewRecords(SourceDocument $document, array $entries): int
    {
        foreach ($entries as $entry) {
            ExtractedRecord::create([
                'source_document_id' => $document->id,
                'record_type' => 'link',
                'payload' => $entry,
                'status' => 'pending',
            ]);
        }
        return count($entries);
    }
}