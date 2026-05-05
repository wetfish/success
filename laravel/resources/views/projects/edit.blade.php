@extends('layouts.app')

@section('title', 'Edit ' . $project->name . ' — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('projects.show', $project) }}" class="link-subtle text-sm">
                ← {{ $project->name }}
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Edit project</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                At {{ $organization->name }}
                @if ($position)
                    · {{ $position->title }}
                @endif
                @if ($parentProject)
                    · sub-project of {{ $parentProject->name }}
                @endif
            </p>
        </div>

        <form method="POST" action="{{ route('projects.update', $project) }}" novalidate>
            @method('PUT')
            @include('projects._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save changes
                </button>
                <a href="{{ route('projects.show', $project) }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection