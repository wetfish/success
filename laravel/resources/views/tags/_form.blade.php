@csrf

<div class="space-y-8">
    <div>
        <label for="name" class="field-label">Name</label>
        <input
            type="text"
            id="name"
            name="name"
            value="{{ old('name', $tag->name) }}"
            required
            autofocus
            maxlength="255"
            class="input @error('name') has-error @enderror"
        >
        @error('name')
            <p class="field-error">{{ $message }}</p>
        @enderror
        <p class="field-help">
            The canonical form. Case and punctuation matter — "C++" is a different tag from "Cpp".
            Add alternate spellings as aliases after saving.
        </p>
    </div>

    <div>
        <label for="category" class="field-label">
            Category
            <span class="field-label-hint">(optional)</span>
        </label>
        <select
            id="category"
            name="category"
            class="input @error('category') has-error @enderror"
        >
            <option value="">Uncategorized</option>
            @foreach ($categories as $category)
                <option
                    value="{{ $category }}"
                    @selected(old('category', $tag->category) === $category)
                >
                    {{ $categoryLabels[$category] ?? ucfirst($category) }}
                </option>
            @endforeach
        </select>
        @error('category')
            <p class="field-error">{{ $message }}</p>
        @enderror
        <p class="field-help">
            A soft hint that groups tags on the index page. Pick the closest fit, or leave uncategorized.
        </p>
    </div>

    <div>
        <label for="description" class="field-label">
            Description
            <span class="field-label-hint">(optional)</span>
        </label>
        <textarea
            id="description"
            name="description"
            rows="3"
            placeholder="What this tag refers to — useful for obscure or specialized concepts"
            class="input @error('description') has-error @enderror"
        >{{ old('description', $tag->description) }}</textarea>
        @error('description')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>
</div>