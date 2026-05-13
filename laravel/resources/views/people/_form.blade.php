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
                value="{{ old('name', $person->name) }}"
                required
                autofocus
                maxlength="255"
                class="input @error('name') has-error @enderror"
            >
            @error('name')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <p class="field-help">
                The only required field. Everything else can be filled in later as you remember it.
            </p>
        </div>
    </div>

    {{-- Identity and current state --}}
    <div class="space-y-5">
        <h2 class="section-heading">Current state (optional)</h2>

        <div>
            <label for="current_title" class="field-label">Current title</label>
            <input
                type="text"
                id="current_title"
                name="current_title"
                value="{{ old('current_title', $person->current_title) }}"
                placeholder="Engineering Manager, Senior PM, etc."
                maxlength="255"
                class="input @error('current_title') has-error @enderror"
            >
            @error('current_title')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="current_organization_id" class="field-label">Current organization</label>
            <select
                id="current_organization_id"
                name="current_organization_id"
                class="input @error('current_organization_id') has-error @enderror"
            >
                <option value="">Unknown / unaffiliated</option>
                @foreach ($organizations as $organization)
                    <option
                        value="{{ $organization->id }}"
                        @selected((int) old('current_organization_id', $person->current_organization_id) === $organization->id)
                    >
                        {{ $organization->name }}
                    </option>
                @endforeach
            </select>
            @error('current_organization_id')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <p class="field-help">
                Where they work now. A person_organization_history table is on the roadmap to track changes over time; for now just the current state.
            </p>
        </div>

        <div>
            <label for="email" class="field-label">
                Email
                <span class="field-label-hint">(optional)</span>
            </label>
            <input
                type="email"
                id="email"
                name="email"
                value="{{ old('email', $person->email) }}"
                placeholder="them@example.com"
                maxlength="255"
                class="input @error('email') has-error @enderror"
            >
            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <p class="field-help">
                Stored lowercased for consistency. For the eventual follow-up cadence feature.
            </p>
        </div>
    </div>

    {{-- Relationship --}}
    <div class="space-y-5">
        <h2 class="section-heading">Relationship (optional)</h2>

        <div>
            <label for="relationship_type" class="field-label">Type</label>
            <select
                id="relationship_type"
                name="relationship_type"
                class="input @error('relationship_type') has-error @enderror"
            >
                <option value="">Don't know yet</option>
                @foreach ($relationshipTypes as $type)
                    <option
                        value="{{ $type }}"
                        @selected(old('relationship_type', $person->relationship_type) === $type)
                    >
                        {{ ucfirst($type) }}
                    </option>
                @endforeach
            </select>
            @error('relationship_type')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <p class="field-help">
                Their primary relationship to you. People can show up in multiple roles across positions — this is the headline summary.
            </p>
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
            placeholder="How you met, what they're like to work with, things to remember"
            class="input @error('user_notes') has-error @enderror"
        >{{ old('user_notes', $person->user_notes) }}</textarea>
        @error('user_notes')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>
</div>