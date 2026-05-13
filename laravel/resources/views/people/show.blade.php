@extends('layouts.app')

@section('title', $person->name . ' — Success')

@php
    /**
     * The collaborator collections are already eager-loaded by the
     * controller. Pre-extract them and the pivot roles for cleaner
     * template rendering. The pivot fields are accessed via
     * $entity->pivot->role_on_<entity>.
     */
    $positions = $person->positions;
    $projects = $person->projects;
    $accomplishments = $person->accomplishments;

    $hasAnyRelationships = $positions->isNotEmpty()
        || $projects->isNotEmpty()
        || $accomplishments->isNotEmpty();
@endphp

@section('content')
    <div class="mb-2">
        <a href="{{ route('people.index') }}" class="link-subtle text-sm">
            ← People
        </a>
    </div>

    <div class="flex items-start justify-between mb-8 gap-4">
        <div class="min-w-0">
            <h1 class="text-3xl font-semibold tracking-tight">{{ $person->name }}</h1>
            @if ($person->current_title)
                <p class="mt-2" style="color: var(--color-text-secondary);">
                    {{ $person->current_title }}
                    @if ($person->currentOrganization)
                        <span style="color: var(--color-text-muted);"> · </span>
                        <a href="{{ route('organizations.show', $person->currentOrganization) }}" class="link-emphasis">
                            {{ $person->currentOrganization->name }}
                        </a>
                    @endif
                </p>
            @elseif ($person->currentOrganization)
                <p class="mt-2" style="color: var(--color-text-secondary);">
                    <a href="{{ route('organizations.show', $person->currentOrganization) }}" class="link-emphasis">
                        {{ $person->currentOrganization->name }}
                    </a>
                </p>
            @endif
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('people.edit', $person) }}" class="btn-secondary">
                Edit
            </a>
            <form
                method="POST"
                action="{{ route('people.destroy', $person) }}"
                onsubmit="return confirm('Delete {{ addslashes($person->name) }}? This action soft-deletes the record — it can be recovered from the database.')"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-destructive">
                    Delete
                </button>
            </form>
        </div>
    </div>

    {{-- Metadata block. Renders only the fields that exist; if all
         fields are empty (e.g. quick-added with just a name) the
         whole block plus its divider collapses. --}}
    @if ($person->relationship_type || $person->email || $person->user_notes)
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-5 mb-12 pb-12 border-b" style="border-color: var(--color-divider);">
            @if ($person->relationship_type)
                <div>
                    <dt class="metadata-label">Relationship</dt>
                    <dd class="mt-1 text-sm capitalize">{{ $person->relationship_type }}</dd>
                </div>
            @endif

            @if ($person->email)
                <div>
                    <dt class="metadata-label">Email</dt>
                    <dd class="mt-1 text-sm">
                        <a href="mailto:{{ $person->email }}" class="link-emphasis break-all">
                            {{ $person->email }}
                        </a>
                    </dd>
                </div>
            @endif

            @if ($person->user_notes)
                <div class="sm:col-span-3">
                    <dt class="metadata-label">Private notes</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line leading-relaxed" style="color: var(--color-text-secondary);">{{ $person->user_notes }}</dd>
                </div>
            @endif
        </dl>
    @endif

    {{-- Relationship surface: where this person appears as a
         collaborator. The three sections render only if non-empty
         so a freshly-added person reads cleanly with just the
         empty state below. --}}
    @if ($hasAnyRelationships)
        @if ($positions->isNotEmpty())
            <div class="mb-12">
                <h2 class="text-lg font-semibold mb-4">Positions</h2>
                <ul
                    class="rounded-lg overflow-hidden border"
                    style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                >
                    @foreach ($positions as $position)
                        <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                            <a href="{{ route('positions.show', $position) }}" class="list-row">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="min-w-0">
                                        <h3 class="font-medium truncate">{{ $position->title }}</h3>
                                        <p class="text-sm truncate mt-0.5" style="color: var(--color-text-secondary);">
                                            {{ $position->organization->name }}
                                        </p>
                                    </div>
                                    @if ($position->pivot->role_on_position)
                                        <div class="text-xs shrink-0" style="color: var(--color-text-muted);">
                                            {{ $position->pivot->role_on_position }}
                                        </div>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($projects->isNotEmpty())
            <div class="mb-12">
                <h2 class="text-lg font-semibold mb-4">Projects</h2>
                <ul
                    class="rounded-lg overflow-hidden border"
                    style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                >
                    @foreach ($projects as $project)
                        <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                            <a href="{{ route('projects.show', $project) }}" class="list-row">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="min-w-0">
                                        <h3 class="font-medium truncate">{{ $project->name }}</h3>
                                        <p class="text-sm truncate mt-0.5" style="color: var(--color-text-secondary);">
                                            {{ $project->organization->name }}
                                        </p>
                                    </div>
                                    @if ($project->pivot->role_on_project)
                                        <div class="text-xs shrink-0" style="color: var(--color-text-muted);">
                                            {{ $project->pivot->role_on_project }}
                                        </div>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($accomplishments->isNotEmpty())
            <div class="mb-12">
                <h2 class="text-lg font-semibold mb-4">Accomplishments</h2>
                <ul
                    class="rounded-lg overflow-hidden border"
                    style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                >
                    @foreach ($accomplishments as $accomplishment)
                        @php
                            /**
                             * Accomplishments belong to exactly one of project
                             * or position. The "where" subline shows whichever
                             * parent exists, with the organization for further
                             * context.
                             */
                            $parentLabel = $accomplishment->project
                                ? $accomplishment->project->name . ' · ' . $accomplishment->project->organization->name
                                : ($accomplishment->position
                                    ? $accomplishment->position->title . ' · ' . $accomplishment->position->organization->name
                                    : null);
                        @endphp
                        <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                            <a href="{{ route('accomplishments.show', $accomplishment) }}" class="list-row">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="min-w-0">
                                        <h3 class="font-medium truncate">{{ $accomplishment->title }}</h3>
                                        @if ($parentLabel)
                                            <p class="text-sm truncate mt-0.5" style="color: var(--color-text-secondary);">
                                                {{ $parentLabel }}
                                            </p>
                                        @endif
                                    </div>
                                    @if ($accomplishment->pivot->role_on_accomplishment)
                                        <div class="text-xs shrink-0" style="color: var(--color-text-muted);">
                                            {{ $accomplishment->pivot->role_on_accomplishment }}
                                        </div>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @else
        <div
            class="border border-dashed rounded-lg p-8 text-center text-sm mb-12"
            style="border-color: var(--color-surface-input-border); color: var(--color-text-secondary);"
        >
            No collaborations recorded yet. Attach {{ $person->name }} to a position, project, or accomplishment using the collaborator picker on those forms.
        </div>
    @endif

    {{-- TODO: Links section for people (LinkedIn, personal site, GitHub, etc.)
         The schema supports it via the morphMany on Person, but the
         link picker's parent-type match in `links/_section.blade.php`
         doesn't currently include Person. Adding it is a focused follow-up:
         one `createForPerson` route, one alias entry in LinkController's
         LINKABLE_MAP, one match arm in the section partial. Tracked
         separately from the people slice. --}}
@endsection