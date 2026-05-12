@php
    /**
     * Inline alias management. Renders below the tag edit form on
     * `tags/edit.blade.php`. Required variable:
     *
     *   $tag — the Tag model instance, with aliases eager-loaded
     *          (the controller does this via `$tag->load('aliases')`).
     *
     * Aliases are immutable once created. The UI offers only "add new"
     * and "delete existing" — no edit affordance. To rename an alias,
     * delete it and add the new spelling.
     */
@endphp

<section id="aliases" class="mt-12 pt-10 border-t" style="border-color: var(--color-divider);">
    <div class="mb-4">
        <h2 class="text-lg font-semibold">Aliases</h2>
        <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
            Alternate spellings that resolve to this tag. When typing
            "postgres" in a tag picker, the user gets "PostgreSQL" if
            postgres is an alias.
        </p>
    </div>

    {{-- Add form. Posts to tag-aliases.store with the parent tag in
         the URL; the alias text is the only field. Errors render
         next to the field rather than at the top of the page,
         since the section is self-contained. --}}
    <form
        method="POST"
        action="{{ route('tag-aliases.store', $tag) }}"
        novalidate
        class="mb-6"
    >
        @csrf

        <label for="new-alias" class="field-label">Add an alias</label>
        <div class="flex gap-2">
            <input
                type="text"
                id="new-alias"
                name="alias"
                value="{{ old('alias') }}"
                placeholder="e.g., postgres"
                maxlength="255"
                required
                class="input flex-1 @error('alias') has-error @enderror"
            >
            <button type="submit" class="btn-primary shrink-0">
                Add
            </button>
        </div>
        @error('alias')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </form>

    {{-- Existing aliases as a flat list. Each row has the alias text
         and an inline delete button. The submit-as-form pattern
         matches the destroy buttons elsewhere in the app (inline
         confirm() guarding a one-field POST). --}}
    @if ($tag->aliases->isEmpty())
        <div
            class="border border-dashed rounded-lg p-6 text-center text-sm"
            style="border-color: var(--color-surface-input-border); color: var(--color-text-secondary);"
        >
            No aliases yet. Add the first spelling above.
        </div>
    @else
        <ul
            class="rounded-lg overflow-hidden border"
            style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
        >
            @foreach ($tag->aliases as $alias)
                <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                    <div class="px-4 py-3 flex items-center justify-between gap-4">
                        <span class="font-medium">{{ $alias->alias }}</span>
                        <form
                            method="POST"
                            action="{{ route('tag-aliases.destroy', [$tag, $alias]) }}"
                            onsubmit="return confirm('Delete the alias &quot;{{ addslashes($alias->alias) }}&quot;?')"
                            class="shrink-0"
                        >
                            @csrf
                            @method('DELETE')
                            <button
                                type="submit"
                                class="link-subtle text-sm"
                                style="color: var(--color-error);"
                            >
                                Delete
                            </button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>