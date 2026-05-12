<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTagRequest;
use App\Http\Requests\TagRules;
use App\Http\Requests\UpdateTagRequest;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tags are flat reference data shared across the application. Unlike
 * other entities, tags have no soft deletes — the cost of accidental
 * deletion is low (definitions are easily recreated) and the FK
 * cascade on `taggables.tag_id` (set up in the join migration) and
 * `tag_aliases.tag_id` cleans up dependent rows automatically.
 *
 * Tags have no show page. The edit page serves double duty as the
 * "view + manage" surface; aliases are managed inline there (see the
 * aliases CRUD slice for that piece).
 *
 * Source document tagging is separate. Per the AI pipeline design,
 * the tags attached to source_documents are AI-populated during
 * extraction and surfaced through a dedicated review screen, not
 * managed via the human tag picker. Usage counts on this controller
 * therefore exclude source_documents — the count represents
 * "user-managed usage," not "total morph rows in `taggables`."
 */
class TagController extends Controller
{
    public function index(): View
    {
        // Usage counts are computed via a single correlated subquery
        // rather than `withCount` over the morph relationships
        // individually, because `withCount` would issue 4–5 separate
        // queries. The subquery filters by taggable_type to exclude
        // SourceDocument rows from the user-facing count.
        //
        // TODO (before SaaS launch): cache tag statistics. At MVP
        // scale (hundreds of tags) this query is fast, but it scans
        // the entire `taggables` table on every index page load and
        // will become a hotspot at scale. Likely a `tag_statistics`
        // table or a Redis-backed counter, updated via observer or
        // queue job on taggable attach/detach. Tracked in the roadmap.
        $userManagedTypes = [
            \App\Models\Organization::class,
            \App\Models\Project::class,
            \App\Models\Position::class,
            \App\Models\Accomplishment::class,
        ];

        $tags = Tag::query()
            ->select('tags.*')
            ->selectSub(
                fn ($q) => $q->from('taggables')
                    ->selectRaw('count(*)')
                    ->whereColumn('taggables.tag_id', 'tags.id')
                    ->whereIn('taggables.taggable_type', $userManagedTypes),
                'usage_count',
            )
            ->with('aliases')
            ->orderBy('name')
            ->get();

        // Grouping by category is presentation logic and could live in
        // the view, but doing it here means the view can iterate over
        // a flat structure without re-traversing the collection.
        // Uncategorized tags collect under a sentinel key that the
        // view renders as "Uncategorized" at the bottom.
        $grouped = $tags->groupBy(fn (Tag $tag) => $tag->category ?? '_uncategorized');

        // Order categories as listed in CATEGORIES, then append the
        // uncategorized bucket last. Categories with zero tags don't
        // appear at all.
        $orderedKeys = collect(TagRules::CATEGORIES)
            ->filter(fn (string $cat) => $grouped->has($cat))
            ->values()
            ->push('_uncategorized')
            ->filter(fn (string $key) => $grouped->has($key));

        return view('tags.index', [
            'groupedTags' => $orderedKeys->mapWithKeys(
                fn (string $key) => [$key => $grouped->get($key)],
            ),
            'totalCount' => $tags->count(),
            'categoryLabels' => TagRules::CATEGORY_LABELS,
        ]);
    }

    public function create(): View
    {
        return view('tags.create', [
            'tag' => new Tag(),
            ...self::dropdownData(),
        ]);
    }

    public function store(StoreTagRequest $request): RedirectResponse
    {
        $tag = Tag::create($request->validated());

        return redirect()
            ->route('tags.edit', $tag)
            ->with('status', "Tag \"{$tag->name}\" created.");
    }

    public function edit(Tag $tag): View
    {
        $tag->load('aliases');

        return view('tags.edit', [
            'tag' => $tag,
            ...self::dropdownData(),
        ]);
    }

    public function update(UpdateTagRequest $request, Tag $tag): RedirectResponse
    {
        $tag->update($request->validated());

        return redirect()
            ->route('tags.edit', $tag)
            ->with('status', "Tag \"{$tag->name}\" updated.");
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        // Hard delete, not soft delete (tags have no deleted_at column).
        // The DB cascades clean up tag_aliases and taggables rows
        // automatically — see the migrations for the FK constraints.
        $name = $tag->name;
        $tag->delete();

        return redirect()
            ->route('tags.index')
            ->with('status', "Tag \"{$name}\" deleted.");
    }

    /**
     * JSON autocomplete endpoint used by the tag picker. Returns up to
     * 5 tags matching the query string, ranked by match quality:
     *
     *   1. Exact-prefix match on canonical name
     *   2. Prefix match on alias
     *   3. Substring match on canonical name
     *   4. Substring match on alias
     *
     * Within each tier, alphabetical. Each result includes the matched
     * alias when relevant, so the picker can show "(matched: postgres)"
     * for transparency on alias matches.
     *
     * Ranking is done in PHP rather than SQL because the four-tier
     * CASE WHEN gets gnarly and the matching set is tiny — hundreds of
     * tags total at MVP scale, of which only a handful match any given
     * substring. The query fetches everything matching (substring on
     * name OR alias), in-memory ranking sorts to find the top 5.
     *
     * Empty or whitespace-only queries return an empty array — the
     * picker is responsible for not firing the request until the user
     * has typed at least one character.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $like = '%' . addcslashes($query, '%_\\') . '%';
        $lowerQuery = mb_strtolower($query);

        // Single query: tags whose canonical name matches OR who have
        // an alias matching. Eager-load aliases so the ranking pass
        // doesn't N+1 when scoring alias matches.
        $candidates = Tag::query()
            ->with('aliases')
            ->where(function ($q) use ($like) {
                $q->where('name', 'LIKE', $like)
                    ->orWhereHas('aliases', fn ($a) => $a->where('alias', 'LIKE', $like));
            })
            ->get();

        // Score each tag and record the alias that matched (if any).
        // Lower tier number = better match. Within a tier, alphabetical
        // by canonical name (stable secondary sort).
        //
        // For each tag, find the best (lowest) tier across all match
        // sources: the canonical name (tiers 1 and 3) and each alias
        // (tiers 2 and 4). If the best tier came from an alias, record
        // which one so the UI can show "(matched: postgres)".
        $scored = $candidates->map(function (Tag $tag) use ($lowerQuery) {
            $matches = [];

            // Tier 1: name prefix, Tier 3: name substring (no alias)
            $name = mb_strtolower($tag->name);
            if (str_starts_with($name, $lowerQuery)) {
                $matches[] = ['tier' => 1, 'alias' => null];
            } elseif (str_contains($name, $lowerQuery)) {
                $matches[] = ['tier' => 3, 'alias' => null];
            }

            // Tier 2: alias prefix, Tier 4: alias substring
            foreach ($tag->aliases as $aliasModel) {
                $aliasLower = mb_strtolower($aliasModel->alias);
                if (str_starts_with($aliasLower, $lowerQuery)) {
                    $matches[] = ['tier' => 2, 'alias' => $aliasModel->alias];
                } elseif (str_contains($aliasLower, $lowerQuery)) {
                    $matches[] = ['tier' => 4, 'alias' => $aliasModel->alias];
                }
            }

            // The match with the lowest tier wins. Ties are broken
            // by whichever came first in the matches array — which
            // means name matches naturally win over equal-tier alias
            // matches (the name is checked first above).
            $best = collect($matches)->sortBy('tier')->first();

            return [
                'tag' => $tag,
                'tier' => $best['tier'] ?? PHP_INT_MAX,
                'matched_alias' => $best['alias'] ?? null,
            ];
        });

        // Stable two-pass sort: sort by name first (alphabetical tie-
        // breaker), then by tier (dominant key). Laravel collection
        // sorts preserve original order for equal keys, so this
        // produces the same result as a multi-criteria sort but reads
        // more clearly.
        $ranked = $scored
            ->sortBy(fn (array $row) => mb_strtolower($row['tag']->name))
            ->sortBy('tier')
            ->take(5)
            ->values();

        return response()->json($ranked->map(fn (array $row) => [
            'id' => $row['tag']->id,
            'name' => $row['tag']->name,
            'category' => $row['tag']->category,
            'category_label' => $row['tag']->category
                ? (TagRules::CATEGORY_LABELS[$row['tag']->category] ?? ucfirst($row['tag']->category))
                : null,
            'matched_alias' => $row['matched_alias'],
        ])->all());
    }

    private static function dropdownData(): array
    {
        return [
            'categories' => TagRules::CATEGORIES,
            'categoryLabels' => TagRules::CATEGORY_LABELS,
        ];
    }
}