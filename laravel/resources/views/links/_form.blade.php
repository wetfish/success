@csrf

{{--
    Hidden polymorphic parent fields only render on create. On edit, the
    UpdateLinkRequest deliberately doesn't include linkable_type or
    linkable_id in its rules, so any tampering with these inputs would
    be silently stripped — but we don't render them at all to keep the
    DOM honest.
--}}
@if (! $link->exists)
    <input type="hidden" name="linkable_type" value="{{ $linkableAlias }}">
    <input type="hidden" name="linkable_id" value="{{ $linkable->id }}">
@endif

<div class="space-y-8">
    {{-- Type — drives the conditional required state on URL and title --}}
    <div>
        <label for="type" class="field-label">Type</label>
        <select
            id="type"
            name="type"
            required
            autofocus
            class="input @error('type') has-error @enderror"
        >
            <option value="">Choose one…</option>
            @foreach ($types as $typeOption)
                <option
                    value="{{ $typeOption }}"
                    @selected(old('type', $link->type) === $typeOption)
                >
                    {{ $typeLabels[$typeOption] ?? ucfirst(str_replace('_', ' ', $typeOption)) }}
                </option>
            @endforeach
        </select>
        @error('type')
            <p class="field-error">{{ $message }}</p>
        @enderror
        <p class="field-help">
            Pick the closest match. The list is scoped to types that fit this kind of parent.
        </p>
    </div>

    {{-- URL — required for everything except internal_doc.
         The `required` attribute is toggled at the bottom of the
         form by inline JS based on the selected type. --}}
    <div>
        <label for="url" class="field-label">URL</label>
        <input
            type="url"
            id="url"
            name="url"
            value="{{ old('url', $link->url) }}"
            placeholder="https://example.com"
            class="input @error('url') has-error @enderror"
        >
        @error('url')
            <p class="field-error">{{ $message }}</p>
        @enderror
        <p class="field-help">
            Optional only for internal documents that aren't shareable.
        </p>
    </div>

    {{-- Title — required for internal_doc, optional otherwise. Same JS
         toggles the required attribute. --}}
    <div>
        <label for="title" class="field-label">Title</label>
        <input
            type="text"
            id="title"
            name="title"
            value="{{ old('title', $link->title) }}"
            placeholder="Display label — what to call this in a list"
            maxlength="255"
            class="input @error('title') has-error @enderror"
        >
        @error('title')
            <p class="field-error">{{ $message }}</p>
        @enderror
        <p class="field-help">
            Required for internal documents (since the URL may be empty). Otherwise optional.
        </p>
    </div>

    {{-- Description — optional context for any link --}}
    <div>
        <label for="description" class="field-label">
            Description
            <span class="field-label-hint">(optional)</span>
        </label>
        <textarea
            id="description"
            name="description"
            rows="3"
            placeholder="What this link is, why it matters, who'd find it useful"
            class="input @error('description') has-error @enderror"
        >{{ old('description', $link->description) }}</textarea>
        @error('description')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Date — when the artifact was published or recorded.
         Useful for media appearances and dated content. --}}
    <div>
        <label for="date" class="field-label">
            Date
            <span class="field-label-hint">(optional)</span>
        </label>
        <input
            type="date"
            id="date"
            name="date"
            value="{{ old('date', $link->date?->format('Y-m-d')) }}"
            class="input @error('date') has-error @enderror"
        >
        @error('date')
            <p class="field-error">{{ $message }}</p>
        @enderror
        <p class="field-help">
            When the artifact was published or recorded. Most useful for media appearances and talks.
        </p>
    </div>

    {{-- Personal appearance flag. Affects how the AI weights this link
         during resume generation — interviews and talks become portfolio
         signal; docs and repos become supporting references. --}}
    <div>
        <label class="inline-flex items-start gap-2 cursor-pointer">
            <input
                type="checkbox"
                name="is_personal_appearance"
                value="1"
                @checked(old('is_personal_appearance', $link->is_personal_appearance))
                style="accent-color: var(--color-accent); margin-top: 0.2rem;"
            >
            <span class="text-sm">
                This is a personal appearance — I'm featured (interview, talk, podcast, etc.)
            </span>
        </label>
        <p class="field-help">
            Personal appearances are weighted as signature evidence during resume generation,
            distinct from supporting links like docs and repos.
        </p>
    </div>
</div>

<script>
    (function () {
        const typeSelect = document.getElementById('type');
        const urlInput = document.getElementById('url');
        const titleInput = document.getElementById('title');

        if (! typeSelect || ! urlInput || ! titleInput) return;

        /* Mirror the server-side rules in HTML5 validation:
         *   type === internal_doc  →  url optional, title required
         *   type === anything else →  url required, title optional
         *   type === '' (unset)    →  neither required (type itself
         *                             will fail validation first)
         */
        function updateRequiredAttrs() {
            const value = typeSelect.value;
            const isInternalDoc = value === 'internal_doc';
            const typePicked = value !== '';

            urlInput.required = typePicked && ! isInternalDoc;
            titleInput.required = isInternalDoc;
        }

        typeSelect.addEventListener('change', updateRequiredAttrs);
        updateRequiredAttrs();
    })();
</script>