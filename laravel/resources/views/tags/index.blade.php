@extends('layouts.app')

@section('title', 'Tags — Success')

@section('content')
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Tags</h1>
            <p class="text-sm mt-1" style="color: var(--color-text-secondary);">
                @if ($totalCount === 0)
                    Skills, technologies, domains — anything you can attach to a project or accomplishment.
                @else
                    {{ $totalCount }} {{ Str::plural('tag', $totalCount) }} across your catalog.
                @endif
            </p>
        </div>
        <a href="{{ route('tags.create') }}" class="btn-primary">
            Add tag
        </a>
    </div>

    @if ($totalCount === 0)
        <div
            class="border border-dashed rounded-lg p-12 text-center"
            style="border-color: var(--color-surface-input-border);"
        >
            <h2 class="text-lg font-medium mb-2">No tags yet</h2>
            <p class="text-sm mb-6 max-w-md mx-auto" style="color: var(--color-text-secondary);">
                Tags let you label projects and accomplishments with the technologies and domains they touch.
                The AI extraction pipeline will populate these automatically once it's wired up — for now, add them manually as you go.
            </p>
            <a href="{{ route('tags.create') }}" class="btn-primary">
                Add your first tag
            </a>
        </div>
    @else
        <div class="space-y-10">
            @foreach ($groupedTags as $categoryKey => $tagsInGroup)
                <div>
                    <h2 class="section-heading mb-3">
                        @if ($categoryKey === '_uncategorized')
                            Uncategorized
                        @else
                            {{ $categoryLabels[$categoryKey] ?? ucfirst($categoryKey) }}
                        @endif
                        <span class="ml-2" style="color: var(--color-text-muted);">
                            {{ $tagsInGroup->count() }}
                        </span>
                    </h2>

                    <ul
                        class="rounded-lg overflow-hidden border"
                        style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                    >
                        @foreach ($tagsInGroup as $tag)
                            <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                                <a href="{{ route('tags.edit', $tag) }}" class="list-row">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0 flex-1">
                                            <h3 class="font-medium">{{ $tag->name }}</h3>
                                            @if ($tag->aliases->isNotEmpty())
                                                <p class="text-xs mt-1" style="color: var(--color-text-secondary);">
                                                    Also known as: {{ $tag->aliases->pluck('alias')->join(', ') }}
                                                </p>
                                            @endif
                                            @if ($tag->description)
                                                <p class="text-sm mt-1" style="color: var(--color-text-secondary);">{{ $tag->description }}</p>
                                            @endif
                                        </div>
                                        <div class="text-xs shrink-0 text-right" style="color: var(--color-text-muted);">
                                            @if ($tag->usage_count > 0)
                                                {{ $tag->usage_count }} {{ Str::plural('use', $tag->usage_count) }}
                                            @else
                                                <span style="color: var(--color-text-muted); opacity: 0.6;">Unused</span>
                                            @endif
                                        </div>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
@endsection