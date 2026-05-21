@extends('layouts.app')

@section('title', 'Add job listing — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('job-listings.index') }}" class="link-subtle text-sm">
                ← Job Listings
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Add job listing</h1>
        </div>

        <form method="POST" action="{{ route('job-listings.store') }}" novalidate>
            @include('job-listings._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save listing
                </button>
                <a href="{{ route('job-listings.index') }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection