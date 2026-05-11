@extends('layouts.app')

@section('title', 'Review draft — Success')

@php
    // Render-friendly labels for the record type values. The raw type
    // is the underscored DB value; this is for display only.
    $typeLabels = [
        'organization' => 'Organization',
        'position' => 'Position',
        'project' => 'Project',
        'accomplishment' => 'Accomplishment',
    ];
    $typeLabel = $typeLabels[$draft->record_type] ?? ucfirst($draft->record_type);

    // Compute progress percentage for the visual bar.
    $progressPercent = $total > 0 ? round(($position / $total) * 100) : 0;

    // Pretty-print payload values. Strings render as-is, arrays as
    // JSON, scalars cast to string. Empty / null values are skipped
    // by the iteration in the view itself.
    $formatValue = function ($value) {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    };

    // Humanize field keys: snake_case → "Snake case", with a few
    // domain-specific overrides for clarity.
    $labelOverrides = [
        'organization_name' => 'Organization',
        'position_title' => 'Position',
        'project_name' => 'Project',
        'parent_project_name' => 'Parent project',
        'employment_type' => 'Employment type',
        'location_arrangement' => 'Location arrangement',
        'location_text' => 'Location',
        'start_date' => 'Start date',
        'end_date' => 'End date',
        'team_name' => 'Team',
        'team_size_immediate' => 'Immediate team size',
        'team_size_extended' => 'Extended team size',
        'reason_for_leaving' => 'Reason for leaving',
        'date_precision' => 'Date precision',
        'contribution_level' => 'Contribution level',
        'contribution_type' => 'Contribution type',
        'team_size' => 'Team size',
        'public_name' => 'Public name',
        'impact_metric' => 'Impact metric',
        'impact_value' => 'Impact value',
        'impact_unit' => 'Impact unit',
        'period_start' => 'Period start',
        'period_end' => 'Period end',
        'founded_year' => 'Founded',
        'size_estimate' => 'Size estimate',
    ];
    $formatLabel = function ($key) use ($labelOverrides) {
        if (isset($labelOverrides[$key])) {
            return $labelOverrides[$key];
        }
        return ucfirst(str_replace('_', ' ', $key));
    };
@endphp

@section('content')
    <div class="mb-2">
        <a href="{{ route('source-documents.show', $sourceDocument) }}" class="link-subtle text-sm">
            ← {{ $sourceDocument->title ?: 'Untitled document' }}
        </a>
    </div>

    {{-- Progress header. Tells the user where they are in the queue
         and visualises remaining work. Gradient bar matches the
         loading-spinner accent treatment so review feels like part
         of the same visual system. --}}
    <div class="mb-8">
        <div class="flex items-baseline justify-between mb-2">
            <h1 class="text-2xl font-semibold tracking-tight">
                Draft {{ $position }} of {{ $total }}
            </h1>
            <p class="text-sm" style="color: var(--color-text-muted);">
                {{ $total - $position + 1 }} pending
            </p>
        </div>
        <div
            class="h-2 rounded-full overflow-hidden"
            style="background: var(--color-surface-input-border);"
            role="progressbar"
            aria-valuenow="{{ $progressPercent }}"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="h-full transition-all"
                style="width: {{ $progressPercent }}%; background: linear-gradient(90deg, rgb(217 70 163 / 0.2), var(--color-accent));"
            ></div>
        </div>
    </div>

    {{-- The draft itself. Record type as the primary label, payload
         contents as a definition list below. Empty/null payload fields
         are skipped so the display stays focused on what the AI
         actually extracted. --}}
    <div
        class="rounded-lg border p-6 mb-8"
        style="background: var(--color-surface-input); border-color: var(--color-surface-input-border);"
    >
        <div class="mb-5 pb-4 border-b" style="border-color: var(--color-divider);">
            <p class="metadata-label mb-1">Record type</p>
            <h2 class="text-xl font-semibold">{{ $typeLabel }}</h2>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
            @foreach ($draft->payload as $key => $value)
                @php
                    $formatted = $formatValue($value);
                @endphp
                @if ($formatted !== '' && $formatted !== 'null')
                    <div class="@if (strlen($formatted) > 80) sm:col-span-2 @endif">
                        <dt class="metadata-label">{{ $formatLabel($key) }}</dt>
                        <dd class="mt-1 text-sm whitespace-pre-line leading-relaxed">{{ $formatted }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
    </div>

    {{-- Placeholder for actions. Confirm, reject, and merge actions
         come in the next mini-slices. --}}
    <div
        class="rounded-lg border border-dashed p-5 mb-8 text-center"
        style="border-color: var(--color-surface-input-border);"
    >
        <p class="text-sm" style="color: var(--color-text-muted);">
            Confirm / reject / merge actions arrive in the next slice.
        </p>
    </div>

    {{-- Prev/Next navigation. Buttons are disabled at the ends of
         the queue rather than hidden, so the user has stable visual
         landmarks throughout. --}}
    <div class="flex items-center justify-between gap-3 pt-6 border-t" style="border-color: var(--color-divider);">
        @if ($prev)
            <a href="{{ route('source-documents.review.show', ['sourceDocument' => $sourceDocument, 'draft' => $prev->id]) }}" class="btn-secondary">
                ← Previous
            </a>
        @else
            <button type="button" class="btn-secondary" disabled style="opacity: 0.4; cursor: not-allowed;">
                ← Previous
            </button>
        @endif

        <a href="{{ route('source-documents.show', $sourceDocument) }}" class="link-subtle text-sm">
            Back to source
        </a>

        @if ($next)
            <a href="{{ route('source-documents.review.show', ['sourceDocument' => $sourceDocument, 'draft' => $next->id]) }}" class="btn-secondary">
                Next →
            </a>
        @else
            <button type="button" class="btn-secondary" disabled style="opacity: 0.4; cursor: not-allowed;">
                Next →
            </button>
        @endif
    </div>
@endsection