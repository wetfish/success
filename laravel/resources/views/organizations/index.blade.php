@extends('layouts.app')

@section('title', 'Organizations — Success')

@section('content')
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Organizations</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                Every employer, client, personal project, and open-source community you've worked with.
            </p>
        </div>
        <a href="{{ route('organizations.create') }}" class="btn-primary">
            Add organization
        </a>
    </div>

    @if ($organizations->isEmpty())
        <div
            class="border border-dashed rounded-lg p-12 text-center"
            style="border-color: var(--color-surface-input-border);"
        >
            <h2 class="text-lg font-medium mb-2">No organizations yet</h2>
            <p class="text-sm mb-6 max-w-md mx-auto" style="color: var(--color-text-secondary);">
                Start by adding the first place you've worked. You'll be able to add positions and projects under it next.
            </p>
            <a href="{{ route('organizations.create') }}" class="btn-primary">
                Add your first organization
            </a>
        </div>
    @else
        <ul
            class="rounded-lg overflow-hidden border"
            style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
        >
            @foreach ($organizations as $organization)
                <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                    <a href="{{ route('organizations.show', $organization) }}" class="list-row">
                        <div class="flex items-center justify-between">
                            <div class="min-w-0">
                                <h3 class="font-medium truncate">{{ $organization->name }}</h3>
                                @if ($organization->tagline)
                                    <p class="text-sm truncate mt-0.5" style="color: var(--color-text-secondary);">{{ $organization->tagline }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 text-xs shrink-0 ml-4" style="color: var(--color-text-muted);">
                                <span class="capitalize">{{ str_replace('_', ' ', $organization->type) }}</span>
                                @if ($organization->status)
                                    <span class="px-2 py-0.5 rounded" style="background: rgb(255 255 255 / 0.06);">{{ $organization->status }}</span>
                                @endif
                            </div>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endsection