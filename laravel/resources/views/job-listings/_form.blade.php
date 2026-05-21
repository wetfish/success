@csrf

<div class="space-y-8">
    {{-- Organization picker --}}
    <div>
        <label for="org-picker-input" class="field-label">Company</label>
        <div
            class="org-picker {{ old('organization_id', $jobListing->organization_id) ? 'has-selection' : '' }}"
            data-org-picker
            data-search-url="{{ route('organizations.search') }}"
            data-quick-store-url="{{ route('organizations.quick-store') }}"
            @if (old('organization_id', $jobListing->organization_id))
                data-selected-id="{{ old('organization_id', $jobListing->organization_id) }}"
                data-selected-name="{{ $jobListing->organization?->name ?? old('organization_name', '') }}"
            @endif
        >
            <div class="org-picker-input-wrap">
                <input
                    type="text"
                    id="org-picker-input"
                    placeholder="Search organizations or type a new name…"
                    autocomplete="off"
                    class="input @error('organization_id') has-error @enderror"
                    data-org-picker-input
                    aria-expanded="false"
                    aria-haspopup="listbox"
                >
                <ul
                    class="org-picker-dropdown"
                    role="listbox"
                    hidden
                    data-org-picker-dropdown
                ></ul>
            </div>

            <div class="org-picker-selected-wrap">
                <span class="org-picker-selected-name" data-org-picker-selected>
                    {{ $jobListing->organization?->name ?? '' }}
                </span>
                <button
                    type="button"
                    class="org-picker-clear"
                    aria-label="Clear selection"
                    data-org-picker-clear
                >
                    <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="2" y1="2" x2="8" y2="8"/>
                        <line x1="8" y1="2" x2="2" y2="8"/>
                    </svg>
                </button>
            </div>

            <input
                type="hidden"
                name="organization_id"
                value="{{ old('organization_id', $jobListing->organization_id) }}"
            >
        </div>
        @error('organization_id')
            <p class="field-error">{{ $message }}</p>
        @enderror
        <p class="field-help">
            Select an existing organization or type a new name to create it as a prospect.
        </p>
    </div>

    {{-- Role title --}}
    <div>
        <label for="role_title" class="field-label">Role title</label>
        <input
            type="text"
            id="role_title"
            name="role_title"
            value="{{ old('role_title', $jobListing->role_title) }}"
            required
            placeholder="Senior Software Engineer, Product Manager, etc."
            class="input @error('role_title') has-error @enderror"
        >
        @error('role_title')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Job listing body --}}
    <div>
        <label for="body" class="field-label">Job listing text</label>
        <textarea
            id="body"
            name="body"
            rows="12"
            required
            placeholder="Paste the full job listing here — requirements, responsibilities, qualifications, everything. The AI uses this to tailor your resume."
            class="input @error('body') has-error @enderror"
        >{{ old('body', $jobListing->body) }}</textarea>
        @error('body')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Optional details --}}
    <div class="space-y-5">
        <h2 class="section-heading">Details (optional)</h2>

        <div>
            <label for="source_url" class="field-label">Source URL</label>
            <input
                type="url"
                id="source_url"
                name="source_url"
                value="{{ old('source_url', $jobListing->source_url) }}"
                placeholder="https://example.com/careers/senior-engineer"
                class="input @error('source_url') has-error @enderror"
            >
            @error('source_url')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <p class="field-help">Where you found the listing. Useful for revisiting later.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label for="location" class="field-label">Location</label>
                <input
                    type="text"
                    id="location"
                    name="location"
                    value="{{ old('location', $jobListing->location) }}"
                    placeholder="Remote, NYC (hybrid), San Francisco"
                    class="input @error('location') has-error @enderror"
                >
                @error('location')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="compensation_range" class="field-label">Compensation</label>
                <input
                    type="text"
                    id="compensation_range"
                    name="compensation_range"
                    value="{{ old('compensation_range', $jobListing->compensation_range) }}"
                    placeholder="$120-150k, competitive, $80/hr"
                    class="input @error('compensation_range') has-error @enderror"
                >
                @error('compensation_range')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div>
            <label for="date_posted" class="field-label">Date posted</label>
            <input
                type="date"
                id="date_posted"
                name="date_posted"
                value="{{ old('date_posted', $jobListing->date_posted?->format('Y-m-d')) }}"
                class="input @error('date_posted') has-error @enderror"
            >
            @error('date_posted')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>
    </div>
</div>