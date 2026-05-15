<?php

namespace App\Services\Resolution;

use App\Models\Tag;
use App\Models\TagAlias;

/**
 * Resolves tag names against the catalog. Two operations:
 *
 *   resolve($name)  — find an existing tag (by name or alias),
 *                     or create a new one. Side-effectful.
 *
 *   preview($name)  — find what would happen WITHOUT creating
 *                     anything. Returns a description for UI use.
 *
 * Lookup order (both methods):
 *   1. Case-insensitive match on tags.name → existing tag
 *   2. Case-insensitive match on tag_aliases.alias → the aliased tag
 *   3. No match → resolve() creates, preview() reports "would create"
 *
 * Auto-create is the right default for AI extraction — requiring the
 * user to pre-create every tag the AI might emit defeats the purpose.
 * Users can later merge duplicates or add aliases on the tag
 * management page. New tags preserve the AI's casing ("Python" stays
 * "Python") but lookup is case-insensitive, so a follow-up extraction
 * emitting "python" resolves to the existing tag.
 *
 * Used by DraftConfirmer at confirm time (resolve), and by the
 * AI-extraction tag review UI at render time (preview) to show the
 * user what would happen if they kept a given tag as-is.
 */
class TagResolver
{
    /**
     * Resolve a name to a Tag. Creates a new tag if no match exists.
     *
     * The optional $category is applied only when creating a new tag.
     * Existing tags keep their stored category — user curation wins
     * over per-document AI guesses. The category is validated against
     * Tag::CATEGORIES; an unrecognized value is silently dropped and
     * the new tag is created with `category = null`, so the review UI
     * can prompt the user to categorize. This matches the defense-in-
     * depth principle: the prompt tells the AI the closed list, but a
     * deviation shouldn't lose the underlying mention.
     */
    public function resolve(string $name, ?string $category = null): Tag
    {
        $trimmed = trim($name);
        $lowered = strtolower($trimmed);

        // 1. Match by canonical name.
        $tag = Tag::query()
            ->whereRaw('LOWER(name) = ?', [$lowered])
            ->first();
        if ($tag) {
            return $tag;
        }

        // 2. Match by alias.
        $tag = Tag::query()
            ->whereHas('aliases', fn ($q) =>
                $q->whereRaw('LOWER(alias) = ?', [$lowered])
            )
            ->first();
        if ($tag) {
            return $tag;
        }

        // 3. Create new with the AI's casing preserved. Category is
        // applied only if it's in the closed enum; otherwise null
        // (defense-in-depth — the AI may emit a category we don't know).
        $validCategory = $category !== null && in_array($category, Tag::CATEGORIES, true)
            ? $category
            : null;

        return Tag::create([
            'name' => $trimmed,
            'category' => $validCategory,
        ]);
    }

    /**
     * Preview what would happen for a given name without making any
     * database changes. Returns an array describing the outcome:
     *
     *   ['status' => 'existing', 'tag' => Tag, 'matched_alias' => null]
     *     — exact name match
     *
     *   ['status' => 'alias', 'tag' => Tag, 'matched_alias' => 'postgres']
     *     — alias match (the AI's name is a known alias)
     *
     *   ['status' => 'new', 'tag' => null, 'matched_alias' => null]
     *     — no match; resolve() would create a new tag
     */
    public function preview(string $name): array
    {
        $trimmed = trim($name);
        $lowered = strtolower($trimmed);

        // 1. Canonical name match.
        $tag = Tag::query()
            ->whereRaw('LOWER(name) = ?', [$lowered])
            ->first();
        if ($tag) {
            return ['status' => 'existing', 'tag' => $tag, 'matched_alias' => null];
        }

        // 2. Alias match — also surface which alias matched, since
        // the review UI may want to show "matched alias 'postgres'".
        $alias = TagAlias::query()
            ->whereRaw('LOWER(alias) = ?', [$lowered])
            ->first();
        if ($alias) {
            return [
                'status' => 'alias',
                'tag' => $alias->tag,
                'matched_alias' => $alias->alias,
            ];
        }

        return ['status' => 'new', 'tag' => null, 'matched_alias' => null];
    }
}