@extends('layouts.app')

@php
    if ($parentProject) {
        $title = 'Add sub-project — Success';
        $heading = 'Add sub-project';
        $context = 'Under ' . $parentProject->name;
        $backUrl = route('projects.show', $parentProject);
        $backLabel = $parentProject->name;
    } elseif ($position) {
        $title = 'Add project — Success';
        $heading = 'Add project';
        $context = 'At ' . $organization->name . ' · ' . $position->title;
        $backUrl = route('positions.show', $position);
        $backLabel = $position->title;
    } else {
        $title = 'Add project — Success';
        $heading = 'Add project';
        $context = 'At ' . $organization->name;
        $backUrl = route('organizations.show', $organization);
        $backLabel = $organization->name;
    }
@endphp

@section('title', $title)

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ $backUrl }}" class="link-subtle text-sm">
                ← {{ $backLabel }}
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">{{ $heading }}</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                {{ $context }}
            </p>
        </div>

        <form method="POST" action="{{ route('projects.store') }}" novalidate>
            @include('projects._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save project
                </button>
                <a href="{{ $backUrl }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection