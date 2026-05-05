@extends('layouts.app')

@section('title', 'Edit ' . $organization->name . ' — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('organizations.show', $organization) }}" class="link-subtle text-sm">
                ← {{ $organization->name }}
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Edit organization</h1>
        </div>

        <form method="POST" action="{{ route('organizations.update', $organization) }}" novalidate>
            @method('PUT')
            @include('organizations._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save changes
                </button>
                <a href="{{ route('organizations.show', $organization) }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection