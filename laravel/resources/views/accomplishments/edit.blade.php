@extends('layouts.app')

@php
    if ($project) {
        $backUrl = route('projects.show', $project);
        $backLabel = $project->name;
        $context = 'In project: ' . $project->name;
    } else {
        $backUrl = route('positions.show', $position);
        $backLabel = $position->title;
        $context = 'In position: ' . $position->title . ' at ' . $position->organization->name;
    }
@endphp

@section('title', 'Edit accomplishment — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('accomplishments.show', $accomplishment) }}" class="link-subtle text-sm">
                ← Accomplishment
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Edit accomplishment</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                {{ $context }}
            </p>
        </div>

        <form method="POST" action="{{ route('accomplishments.update', $accomplishment) }}" novalidate>
            @method('PUT')
            @include('accomplishments._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save changes
                </button>
                <a href="{{ route('accomplishments.show', $accomplishment) }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection