@extends('layouts.app')

@section('title', 'Add position at ' . $organization->name . ' — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('organizations.show', $organization) }}" class="link-subtle text-sm">
                ← {{ $organization->name }}
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Add position</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                At {{ $organization->name }}
            </p>
        </div>

        <form method="POST" action="{{ route('positions.store') }}" novalidate>
            @include('positions._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save position
                </button>
                <a href="{{ route('organizations.show', $organization) }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection