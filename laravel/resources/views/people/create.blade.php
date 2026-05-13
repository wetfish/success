@extends('layouts.app')

@section('title', 'Add person — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('people.index') }}" class="link-subtle text-sm">
                ← People
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Add person</h1>
        </div>

        <form method="POST" action="{{ route('people.store') }}" novalidate>
            @include('people._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save person
                </button>
                <a href="{{ route('people.index') }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection