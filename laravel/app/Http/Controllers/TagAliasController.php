<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTagAliasRequest;
use App\Models\Tag;
use App\Models\TagAlias;
use Illuminate\Http\RedirectResponse;

/**
 * Aliases are nested under a tag at the URL level. Both store and
 * destroy take the parent tag as a route parameter rather than
 * recovering it from the alias's belongsTo — aliases always have
 * exactly one parent type (unlike links, which are polymorphic),
 * so the nested URL is unambiguous and easier to reason about.
 *
 * No create page, no edit form, no show or index. Aliases display
 * inline on the tag edit page via the `tags._aliases_section`
 * partial, and they're immutable once created (destroy + recreate
 * to "rename"). See TagAliasRules for the rationale.
 *
 * After store and destroy, redirect back to the tag's edit page
 * with a focus anchor so the user lands inside the alias section
 * rather than at the top of the page.
 */
class TagAliasController extends Controller
{
    public function store(StoreTagAliasRequest $request, Tag $tag): RedirectResponse
    {
        $tag->aliases()->create([
            'alias' => $request->validated()['alias'],
        ]);

        return redirect()
            ->to(route('tags.edit', $tag) . '#aliases')
            ->with('status', "Alias added.");
    }

    public function destroy(Tag $tag, TagAlias $alias): RedirectResponse
    {
        // Guard against URL tampering — the alias must actually
        // belong to the tag in the URL. Without this check, a user
        // could craft `/tags/5/aliases/99` and delete alias 99 even
        // though it belongs to tag 7. The route binding loads both
        // records but doesn't verify the relationship.
        abort_unless($alias->tag_id === $tag->id, 404);

        $aliasText = $alias->alias;
        $alias->delete();

        return redirect()
            ->to(route('tags.edit', $tag) . '#aliases')
            ->with('status', "Alias \"{$aliasText}\" deleted.");
    }
}