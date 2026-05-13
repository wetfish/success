@extends('layouts.app')

@section('title', 'People — Success')

@php
    /**
     * Group people by their current organization, with "Unaffiliated"
     * as the final bucket for people without a current_organization_id.
     * Within each group, alphabetical by name (preserved from the
     * controller's orderBy).
     *
     * The grouping uses the organization's ID as the key so two people
     * at the same org cluster together correctly. We sort the groups
     * alphabetically by org name, with the unaffiliated bucket last
     * regardless.
     *
     * Row markup is intentionally duplicated between the named groups
     * and the unaffiliated bucket rather than extracted to a sub-partial,
     * to match the existing convention in `links/_section.blade.php`
     * and `organizations/show.blade.php`.
     */
    $grouped = $people->groupBy(fn ($p) => $p->currentOrganization?->id ?? '_unaffiliated');

    // Filter rather than except(): Eloquent\Collection::except() is
    // overridden to interpret its argument as a model key and calls
    // getKey() on the items being excluded, which fails because the
    // grouped values are plain Collections, not models. Using filter()
    // sidesteps the override and works on a key match directly.
    $namedGroups = $grouped
        ->filter(fn ($group, $key) => $key !== '_unaffiliated')
        ->sortBy(fn ($group) => $group->first()->currentOrganization->name);

    $unaffiliated = $grouped->get('_unaffiliated', collect());
@endphp

@section('content')
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">People</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                @if ($totalCount === 0)
                    Managers, collaborators, mentors — anyone you've worked with.
                @else
                    {{ $totalCount }} {{ Str::plural('person', $totalCount === 1 ? 1 : 2) }} across your network.
                @endif
            </p>
        </div>
        <a href="{{ route('people.create') }}" class="btn-primary">
            Add person
        </a>
    </div>

    @if ($totalCount === 0)
        <div
            class="border border-dashed rounded-lg p-12 text-center"
            style="border-color: var(--color-surface-input-border);"
        >
            <h2 class="text-lg font-medium mb-2">No people yet</h2>
            <p class="text-sm mb-6 max-w-md mx-auto" style="color: var(--color-text-secondary);">
                People you can attach to positions, projects, and accomplishments as collaborators. You can also add people on the fly from any form's collaborator picker.
            </p>
            <a href="{{ route('people.create') }}" class="btn-primary">
                Add your first person
            </a>
        </div>
    @else
        <div class="space-y-10">
            @foreach ($namedGroups as $organizationId => $groupPeople)
                @php $organization = $groupPeople->first()->currentOrganization; @endphp
                <div>
                    <h2 class="section-heading mb-3">
                        <a href="{{ route('organizations.show', $organization) }}" class="link-emphasis">
                            {{ $organization->name }}
                        </a>
                        <span class="ml-2" style="color: var(--color-text-muted);">
                            {{ $groupPeople->count() }}
                        </span>
                    </h2>

                    <ul
                        class="rounded-lg overflow-hidden border"
                        style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                    >
                        @foreach ($groupPeople as $person)
                            <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                                <a href="{{ route('people.show', $person) }}" class="list-row">
                                    <div class="flex items-center justify-between gap-4">
                                        <div class="min-w-0">
                                            <h3 class="font-medium truncate">{{ $person->name }}</h3>
                                            @if ($person->current_title)
                                                <p class="text-sm truncate mt-0.5" style="color: var(--color-text-secondary);">{{ $person->current_title }}</p>
                                            @endif
                                        </div>
                                        @if ($person->relationship_type)
                                            <div class="text-xs shrink-0 capitalize" style="color: var(--color-text-muted);">
                                                {{ $person->relationship_type }}
                                            </div>
                                        @endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            @if ($unaffiliated->isNotEmpty())
                <div>
                    <h2 class="section-heading mb-3">
                        Unaffiliated
                        <span class="ml-2" style="color: var(--color-text-muted);">
                            {{ $unaffiliated->count() }}
                        </span>
                    </h2>

                    <ul
                        class="rounded-lg overflow-hidden border"
                        style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                    >
                        @foreach ($unaffiliated as $person)
                            <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                                <a href="{{ route('people.show', $person) }}" class="list-row">
                                    <div class="flex items-center justify-between gap-4">
                                        <div class="min-w-0">
                                            <h3 class="font-medium truncate">{{ $person->name }}</h3>
                                            @if ($person->current_title)
                                                <p class="text-sm truncate mt-0.5" style="color: var(--color-text-secondary);">{{ $person->current_title }}</p>
                                            @endif
                                        </div>
                                        @if ($person->relationship_type)
                                            <div class="text-xs shrink-0 capitalize" style="color: var(--color-text-muted);">
                                                {{ $person->relationship_type }}
                                            </div>
                                        @endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
@endsection