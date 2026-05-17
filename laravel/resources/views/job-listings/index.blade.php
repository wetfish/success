@extends('layouts.app')

@section('title', 'Job Listings — Success')

@section('content')
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Job Listings</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                Roles you're targeting. Each listing becomes the starting point for a tailored resume.
            </p>
        </div>
        <a href="{{ route('job-listings.create') }}" class="btn-primary">
            Add listing
        </a>
    </div>

    @if ($jobListings->isEmpty())
        <div
            class="border border-dashed rounded-lg p-12 text-center"
            style="border-color: var(--color-surface-input-border);"
        >
            <h2 class="text-lg font-medium mb-2">No job listings yet</h2>
            <p class="text-sm mb-6 max-w-md mx-auto" style="color: var(--color-text-secondary);">
                Paste a job listing to get started. The system will compare it against your catalog and generate a tailored resume.
            </p>
            <a href="{{ route('job-listings.create') }}" class="btn-primary">
                Add your first listing
            </a>
        </div>
    @else
        <ul
            class="rounded-lg overflow-hidden border"
            style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
        >
            @foreach ($jobListings as $jobListing)
                <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                    <a href="{{ route('job-listings.show', $jobListing) }}" class="list-row">
                        <div class="flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <h3 class="font-medium truncate">{{ $jobListing->role_title }}</h3>
                                <p class="text-sm truncate mt-0.5" style="color: var(--color-text-secondary);">
                                    {{ $jobListing->organization->name }}
                                    @if ($jobListing->location)
                                        · {{ $jobListing->location }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-3 text-xs shrink-0" style="color: var(--color-text-muted);">
                                @if ($jobListing->compensation_range)
                                    <span>{{ $jobListing->compensation_range }}</span>
                                @endif
                                <span class="capitalize">{{ $jobListing->status }}</span>
                            </div>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endsection