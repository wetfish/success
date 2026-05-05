@extends('layouts.app')

@section('title', 'Add organization — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('organizations.index') }}" class="link-subtle text-sm">
                ← Organizations
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Add organization</h1>
        </div>

        <form method="POST" action="{{ route('organizations.store') }}" novalidate>
            @include('organizations._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save organization
                </button>
                <a href="{{ route('organizations.index') }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection