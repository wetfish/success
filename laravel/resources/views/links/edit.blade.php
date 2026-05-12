@extends('layouts.app')

@section('title', 'Edit link — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ $backUrl }}" class="link-subtle text-sm">
                ← {{ $backLabel }}
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Edit link</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                {{ $context }}
            </p>
        </div>

        <form method="POST" action="{{ route('links.update', $link) }}" novalidate>
            @method('PUT')
            @include('links._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save changes
                </button>
                <a href="{{ $backUrl }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection