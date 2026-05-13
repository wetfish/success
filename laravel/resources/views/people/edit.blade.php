@extends('layouts.app')

@section('title', 'Edit ' . $person->name . ' — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('people.show', $person) }}" class="link-subtle text-sm">
                ← {{ $person->name }}
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Edit person</h1>
        </div>

        <form method="POST" action="{{ route('people.update', $person) }}" novalidate>
            @method('PUT')
            @include('people._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save changes
                </button>
                <a href="{{ route('people.show', $person) }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection