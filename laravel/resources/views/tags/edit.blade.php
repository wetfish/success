@extends('layouts.app')

@section('title', 'Edit ' . $tag->name . ' — Success')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-8 flex items-start justify-between gap-4">
            <div class="min-w-0">
                <a href="{{ route('tags.index') }}" class="link-subtle text-sm">
                    ← Tags
                </a>
                <h1 class="text-2xl font-semibold tracking-tight mt-2 truncate">
                    {{ $tag->name }}
                </h1>
            </div>

            {{-- Delete lives in the header. Tags have no show page, so
                 the edit page is the only surface where destruction
                 makes sense to expose. Hard-deletes (no soft delete on
                 the tags table); the FK cascade cleans up aliases and
                 polymorphic taggables rows. --}}
            <form
                method="POST"
                action="{{ route('tags.destroy', $tag) }}"
                onsubmit="return confirm('Delete the tag &quot;{{ addslashes($tag->name) }}&quot;? This removes it from every entity it&apos;s attached to, and the action cannot be undone.')"
                class="shrink-0"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-destructive">
                    Delete
                </button>
            </form>
        </div>

        <form method="POST" action="{{ route('tags.update', $tag) }}" novalidate>
            @method('PUT')
            @include('tags._form')

            <div class="flex items-center gap-3 mt-10 pt-6 border-t" style="border-color: var(--color-divider);">
                <button type="submit" class="btn-primary">
                    Save changes
                </button>
                <a href="{{ route('tags.index') }}" class="link-subtle text-sm">
                    Cancel
                </a>
            </div>
        </form>

        @include('tags._aliases_section', ['tag' => $tag])
    </div>
@endsection