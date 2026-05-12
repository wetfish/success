@php
    /**
     * Reusable partial. Renders the "Links" section for any polymorphic
     * parent. Required variable:
     *
     *   $linkable — the parent model instance (Organization, Project,
     *               Position, or Accomplishment for now; Person will be
     *               added with the Person UI slice).
     *
     * Resolves the "Add link" route from the parent's class. Sorts
     * personal appearances above supporting links, and within each
     * group sorts by date desc then id for stable ordering.
     *
     * Row markup is intentionally duplicated between the appearances
     * and supporting groups rather than extracted to a sub-partial, to
     * match the existing convention (see organizations/show.blade.php
     * where positions and other-projects lists are similarly inline).
     */

    $typeLabels = \App\Http\Requests\LinkRules::TYPE_LABELS;

    $addRoute = match (true) {
        $linkable instanceof \App\Models\Organization
            => route('links.createForOrganization', $linkable),
        $linkable instanceof \App\Models\Project
            => route('links.createForProject', $linkable),
        $linkable instanceof \App\Models\Position
            => route('links.createForPosition', $linkable),
        $linkable instanceof \App\Models\Accomplishment
            => route('links.createForAccomplishment', $linkable),
    };

    $links = $linkable->links()
        ->orderByDesc('is_personal_appearance')
        ->orderByDesc('date')
        ->orderBy('id')
        ->get();

    $appearances = $links->where('is_personal_appearance', true);
    $supporting = $links->where('is_personal_appearance', false);
@endphp

<div>
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold">Links</h2>
        <a href="{{ $addRoute }}" class="btn-primary">
            Add link
        </a>
    </div>

    @if ($links->isEmpty())
        <div
            class="border border-dashed rounded-lg p-8 text-center text-sm"
            style="border-color: var(--color-surface-input-border); color: var(--color-text-secondary);"
        >
            Websites, repos, docs, talks, interviews — anything that lives somewhere external goes here.
        </div>
    @else
        @if ($appearances->isNotEmpty())
            <div class="@if ($supporting->isNotEmpty()) mb-6 @endif">
                @if ($supporting->isNotEmpty())
                    <h3 class="section-heading mb-3">Personal appearances</h3>
                @endif
                <ul
                    class="rounded-lg overflow-hidden border"
                    style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                >
                    @foreach ($appearances as $link)
                        <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                            <div class="px-4 py-3 flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap text-xs" style="color: var(--color-text-muted);">
                                        <span class="uppercase tracking-wide">
                                            {{ $typeLabels[$link->type] ?? ucfirst(str_replace('_', ' ', $link->type)) }}
                                        </span>
                                        @if ($link->date)
                                            <span>·</span>
                                            <span>{{ $link->date->format('M Y') }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 min-w-0">
                                        @if ($link->url)
                                            <a href="{{ $link->url }}" target="_blank" rel="noopener" class="link-emphasis font-medium break-all">
                                                {{ $link->title ?: $link->url }}
                                            </a>
                                        @else
                                            <span class="font-medium">{{ $link->title }}</span>
                                        @endif
                                    </div>
                                    @if ($link->description)
                                        <p class="text-sm mt-1" style="color: var(--color-text-secondary);">{{ $link->description }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 shrink-0 text-sm">
                                    <a href="{{ route('links.edit', $link) }}" class="link-subtle">
                                        Edit
                                    </a>
                                    <form
                                        method="POST"
                                        action="{{ route('links.destroy', $link) }}"
                                        onsubmit="return confirm('Delete this link?')"
                                        class="inline"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            class="link-subtle"
                                            style="color: var(--color-error);"
                                        >
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($supporting->isNotEmpty())
            <div>
                @if ($appearances->isNotEmpty())
                    <h3 class="section-heading mb-3">Supporting links</h3>
                @endif
                <ul
                    class="rounded-lg overflow-hidden border"
                    style="border-color: var(--color-surface-input-border); background: var(--color-surface-input);"
                >
                    @foreach ($supporting as $link)
                        <li class="@if (! $loop->first) border-t @endif" style="border-color: var(--color-divider);">
                            <div class="px-4 py-3 flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap text-xs" style="color: var(--color-text-muted);">
                                        <span class="uppercase tracking-wide">
                                            {{ $typeLabels[$link->type] ?? ucfirst(str_replace('_', ' ', $link->type)) }}
                                        </span>
                                        @if ($link->date)
                                            <span>·</span>
                                            <span>{{ $link->date->format('M Y') }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 min-w-0">
                                        @if ($link->url)
                                            <a href="{{ $link->url }}" target="_blank" rel="noopener" class="link-emphasis font-medium break-all">
                                                {{ $link->title ?: $link->url }}
                                            </a>
                                        @else
                                            <span class="font-medium">{{ $link->title }}</span>
                                        @endif
                                    </div>
                                    @if ($link->description)
                                        <p class="text-sm mt-1" style="color: var(--color-text-secondary);">{{ $link->description }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 shrink-0 text-sm">
                                    <a href="{{ route('links.edit', $link) }}" class="link-subtle">
                                        Edit
                                    </a>
                                    <form
                                        method="POST"
                                        action="{{ route('links.destroy', $link) }}"
                                        onsubmit="return confirm('Delete this link?')"
                                        class="inline"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            class="link-subtle"
                                            style="color: var(--color-error);"
                                        >
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</div>