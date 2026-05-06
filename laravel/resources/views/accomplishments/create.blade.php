@extends('layouts.app')

@php
    if ($project) {
        $context = 'For project: ' . $project->name;
        $backUrl = route('projects.show', $project);
        $backLabel = $project->name;
    } else {
        $context = 'For position: ' . $position->title . ' at ' . $position->organization->name;
        $backUrl = route('positions.show', $position);
        $backLabel = $position->title;
    }
@endphp

@section('title', 'Add accomplishment — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ $backUrl }}" class="link-subtle text-sm">
                ← {{ $backLabel }}
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Add accomplishment</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                {{ $context }}
            </p>
        </div>

        <form method="POST" action="{{ route('accomplishments.store') }}" novalidate>
            @include('accomplishments._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save accomplishment
                </button>
                <a href="{{ $backUrl }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection