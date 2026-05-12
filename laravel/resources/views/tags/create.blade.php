@extends('layouts.app')

@section('title', 'Add tag — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('tags.index') }}" class="link-subtle text-sm">
                ← Tags
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Add tag</h1>
        </div>

        <form method="POST" action="{{ route('tags.store') }}" novalidate>
            @include('tags._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save tag
                </button>
                <a href="{{ route('tags.index') }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection