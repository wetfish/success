@extends('layouts.app')

@section('title', 'Edit ' . $position->title . ' — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('positions.show', $position) }}" class="link-subtle text-sm">
                ← {{ $position->title }}
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Edit position</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                At {{ $organization->name }}
            </p>
        </div>

        <form method="POST" action="{{ route('positions.update', $position) }}" novalidate>
            @method('PUT')
            @include('positions._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save changes
                </button>
                <a href="{{ route('positions.show', $position) }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection