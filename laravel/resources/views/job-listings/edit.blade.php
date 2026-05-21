@extends('layouts.app')

@section('title', 'Edit ' . $jobListing->role_title . ' — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8">
            <a href="{{ route('job-listings.show', $jobListing) }}" class="link-subtle text-sm">
                ← {{ $jobListing->role_title }}
            </a>
            <h1 class="text-2xl font-semibold tracking-tight mt-2">Edit job listing</h1>
        </div>

        <form method="POST" action="{{ route('job-listings.update', $jobListing) }}" novalidate>
            @method('PUT')
            @include('job-listings._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save changes
                </button>
                <a href="{{ route('job-listings.show', $jobListing) }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>
    </div>
@endsection