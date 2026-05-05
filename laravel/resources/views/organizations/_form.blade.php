@csrf

<div class="space-y-8">
    {{-- Required basics --}}
    <div class="space-y-5">
        <div>
            <label for="name" class="field-label">Name</label>
            <input
                type="text"
                id="name"
                name="name"
                value="{{ old('name', $organization->name) }}"
                required
                autofocus
                class="input @error('name') has-error @enderror"
            >
            @error('name')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="type" class="field-label">Type</label>
            <select
                id="type"
                name="type"
                required
                class="input @error('type') has-error @enderror"
            >
                <option value="">Choose one…</option>
                @foreach ($types as $type)
                    <option
                        value="{{ $type }}"
                        @selected(old('type', $organization->type) === $type)
                    >
                        {{ ucfirst(str_replace('_', ' ', $type)) }}
                    </option>
                @endforeach
            </select>
            @error('type')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <p class="field-help">
                Employer, client (for freelance), personal (your own projects), open source, volunteer, or educational institution.
            </p>
        </div>
    </div>

    {{-- Optional context --}}
    <div class="space-y-5">
        <h2 class="section-heading">Context (optional)</h2>

        <div>
            <label for="website" class="field-label">Website</label>
            <input
                type="url"
                id="website"
                name="website"
                value="{{ old('website', $organization->website) }}"
                placeholder="https://example.com"
                class="input @error('website') has-error @enderror"
            >
            @error('website')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="tagline" class="field-label">Tagline</label>
            <input
                type="text"
                id="tagline"
                name="tagline"
                value="{{ old('tagline', $organization->tagline) }}"
                placeholder="What they say about themselves in one line"
                class="input @error('tagline') has-error @enderror"
            >
            @error('tagline')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="description" class="field-label">Description</label>
            <textarea
                id="description"
                name="description"
                rows="4"
                placeholder="What they do, their industry, their products"
                class="input @error('description') has-error @enderror"
            >{{ old('description', $organization->description) }}</textarea>
            @error('description')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="headquarters" class="field-label">Headquarters</label>
            <input
                type="text"
                id="headquarters"
                name="headquarters"
                value="{{ old('headquarters', $organization->headquarters) }}"
                placeholder="NYC, Berlin (remote-first), Distributed"
                class="input @error('headquarters') has-error @enderror"
            >
            @error('headquarters')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Optional metadata --}}
    <div class="space-y-5">
        <h2 class="section-heading">Metadata (optional)</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label for="founded_year" class="field-label">Founded</label>
                <input
                    type="text"
                    inputmode="numeric"
                    id="founded_year"
                    name="founded_year"
                    value="{{ old('founded_year', $organization->founded_year) }}"
                    placeholder="2017"
                    class="input @error('founded_year') has-error @enderror"
                >
                @error('founded_year')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="size_estimate" class="field-label">Size</label>
                <input
                    type="text"
                    id="size_estimate"
                    name="size_estimate"
                    value="{{ old('size_estimate', $organization->size_estimate) }}"
                    placeholder="30-40, ~10, Fortune 500"
                    class="input @error('size_estimate') has-error @enderror"
                >
                @error('size_estimate')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div>
            <label for="status" class="field-label">Status</label>
            <select
                id="status"
                name="status"
                class="input @error('status') has-error @enderror"
            >
                <option value="">Don't know yet</option>
                @foreach ($statuses as $status)
                    <option
                        value="{{ $status }}"
                        @selected(old('status', $organization->status) === $status)
                    >
                        {{ ucfirst($status) }}
                    </option>
                @endforeach
            </select>
            @error('status')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Notes --}}
    <div>
        <label for="user_notes" class="field-label">
            Private notes
            <span class="field-label-hint">(optional, never leaves your catalog)</span>
        </label>
        <textarea
            id="user_notes"
            name="user_notes"
            rows="4"
            placeholder="Anything that doesn't fit elsewhere — backstory, your impressions, things to remember"
            class="input @error('user_notes') has-error @enderror"
        >{{ old('user_notes', $organization->user_notes) }}</textarea>
        @error('user_notes')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>
</div>