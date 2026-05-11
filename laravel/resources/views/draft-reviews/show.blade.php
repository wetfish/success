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

    // Compute progress percentage for the visual bar. Progress is
    // measured by how many drafts have been reviewed (any non-pending
    // status), not by current queue position. Position is shown
    // separately as a numeric label.
    $progressPercent = $totalCount > 0 ? round(($reviewedCount / $totalCount) * 100) : 0;

    // For pending drafts the body is a form; for non-pending drafts
    // (rejected, confirmed, merged) it's read-only display. Both modes
    // use the field schema to decide which fields to show — required
    // fields always render, optional fields render only if the payload
    // has a value.
    $isEditable = $draft->status === 'pending';
    $payload = $draft->payload ?? [];

    $shouldRenderField = function ($key, $config) use ($payload) {
        if ($config['required'] ?? false) {
            return true;
        }
        $value = $payload[$key] ?? null;
        return $value !== null && $value !== '';
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
                {{ $reviewedCount }} of {{ $totalCount }} reviewed
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
            <div class="flex items-center gap-3 flex-wrap">
                <h2 class="text-xl font-semibold">{{ $typeLabel }}</h2>
                @if ($draft->status === 'rejected')
                    <span class="status-badge status-badge-rejected">Rejected</span>
                @elseif ($draft->status === 'confirmed')
                    <span class="status-badge status-badge-confirmed">Confirmed</span>
                @elseif ($draft->status === 'merged')
                    <span class="status-badge status-badge-merged">Merged</span>
                @endif
            </div>
        </div>

        @if ($isEditable)
            {{-- Editable form. The Confirm button submits this form,
                 so user edits flow straight into the confirmation —
                 there's no separate save step. Reject is a separate
                 form below the card so HTML doesn't end up with
                 illegal nested forms. --}}
            <form
                id="confirm-form"
                action="{{ route('source-documents.review.confirm', ['sourceDocument' => $sourceDocument, 'draft' => $draft->id]) }}"
                method="POST"
            >
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
                    @foreach ($fieldSchema as $key => $config)
                        @if ($shouldRenderField($key, $config))
                            @php
                                $value = $payload[$key] ?? '';
                                $type = $config['type'];
                                $required = $config['required'] ?? false;
                                $isTextarea = $type === 'textarea';
                                $colSpan = $isTextarea ? 'sm:col-span-2' : '';
                            @endphp
                            <div class="{{ $colSpan }}">
                                <label for="field-{{ $key }}" class="metadata-label block mb-1">
                                    {{ $config['label'] }}
                                    @if ($required)
                                        <span style="color: var(--color-accent);" aria-label="required">*</span>
                                    @endif
                                </label>
                                @if ($type === 'textarea')
                                    <textarea
                                        id="field-{{ $key }}"
                                        name="{{ $key }}"
                                        class="input"
                                        rows="3"
                                        @if ($required) required @endif
                                    >{{ $value }}</textarea>
                                @elseif ($type === 'select')
                                    <select
                                        id="field-{{ $key }}"
                                        name="{{ $key }}"
                                        class="input"
                                        @if ($required) required @endif
                                    >
                                        <option value="">—</option>
                                        @foreach ($config['options'] as $option)
                                            <option value="{{ $option }}" @if ($value === $option) selected @endif>
                                                {{ str_replace('_', ' ', $option) }}
                                            </option>
                                        @endforeach
                                    </select>
                                @elseif ($type === 'date')
                                    <input
                                        type="date"
                                        id="field-{{ $key }}"
                                        name="{{ $key }}"
                                        value="{{ $value }}"
                                        class="input"
                                        @if ($required) required @endif
                                    >
                                @elseif ($type === 'number')
                                    <input
                                        type="number"
                                        id="field-{{ $key }}"
                                        name="{{ $key }}"
                                        value="{{ $value }}"
                                        class="input"
                                        @if ($required) required @endif
                                    >
                                @else
                                    <input
                                        type="text"
                                        id="field-{{ $key }}"
                                        name="{{ $key }}"
                                        value="{{ $value }}"
                                        class="input"
                                        @if ($required) required @endif
                                    >
                                @endif
                                @if (! empty($config['help']))
                                    <p class="text-xs mt-1" style="color: var(--color-text-muted);">
                                        {{ $config['help'] }}
                                    </p>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            </form>
        @else
            {{-- Read-only display for non-pending drafts. Mirrors the
                 form structure but renders values as text rather than
                 inputs. Only fields with values are shown. --}}
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
                @foreach ($fieldSchema as $key => $config)
                    @php
                        $value = $payload[$key] ?? null;
                    @endphp
                    @if ($value !== null && $value !== '')
                        @php
                            $displayValue = is_array($value)
                                ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                : (string) $value;
                            $colSpan = strlen($displayValue) > 80 ? 'sm:col-span-2' : '';
                        @endphp
                        <div class="{{ $colSpan }}">
                            <dt class="metadata-label">{{ $config['label'] }}</dt>
                            <dd class="mt-1 text-sm whitespace-pre-line leading-relaxed">{{ $displayValue }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        @endif
    </div>

    {{-- Action bar. Branches on the draft's status. For pending,
         the Confirm submit button targets the form above; Reject is
         its own form. For rejected, a Restore form. Confirmed/merged
         show a status note only. --}}
    <div class="flex items-center justify-end gap-3 mb-8">
        @if ($draft->status === 'pending')
            <p class="text-xs mr-auto" style="color: var(--color-text-muted);">
                <span style="color: var(--color-accent);">*</span> Required.
                Merge for duplicates arrives in the next slice.
            </p>

            <button type="submit" form="confirm-form" class="btn-primary">Confirm</button>

            @if ($dependentCount > 0)
                <button
                    type="button"
                    class="btn-destructive"
                    data-reject-trigger
                >
                    Reject
                </button>
            @else
                <form
                    action="{{ route('source-documents.review.reject', ['sourceDocument' => $sourceDocument, 'draft' => $draft->id]) }}"
                    method="POST"
                    class="inline"
                >
                    @csrf
                    <button type="submit" class="btn-destructive">Reject</button>
                </form>
            @endif
        @elseif ($draft->status === 'rejected')
            <p class="text-xs mr-auto" style="color: var(--color-text-muted);">
                This draft was rejected. Restore it to pending to review again.
            </p>
            <form
                action="{{ route('source-documents.review.restore', ['sourceDocument' => $sourceDocument, 'draft' => $draft->id]) }}"
                method="POST"
                class="inline"
            >
                @csrf
                <button type="submit" class="btn-secondary">Restore to pending</button>
            </form>
        @else
            <p class="text-xs mr-auto" style="color: var(--color-text-muted);">
                @if ($draft->status === 'confirmed')
                    This draft has been confirmed and added to your catalog.
                @else
                    Actions for {{ $draft->status }} drafts arrive in upcoming slices.
                @endif
            </p>
        @endif
    </div>

    {{-- Cascade confirmation modal. Only rendered when the draft is
         still pending AND has dependent drafts. The user sees how
         many will be affected and confirms before the cascade runs.
         Backdrop click, Escape key, and the explicit Cancel button
         all dismiss it. --}}
    @if ($draft->status === 'pending' && $dependentCount > 0)
        <div
            class="modal-overlay"
            data-reject-modal
            role="dialog"
            aria-modal="true"
            aria-labelledby="reject-modal-heading"
            inert
        >
            <div class="modal-backdrop" data-reject-backdrop aria-hidden="true"></div>
            <div class="modal-panel">
                <h2 id="reject-modal-heading" class="modal-title">
                    Reject this {{ strtolower($typeLabel) }}?
                </h2>
                <p class="modal-message">
                    This will also reject {{ $dependentCount }} dependent
                    {{ $dependentCount === 1 ? 'draft' : 'drafts' }}
                    that {{ $dependentCount === 1 ? 'references' : 'reference' }}
                    this {{ strtolower($typeLabel) }}.
                </p>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" data-reject-cancel>
                        Cancel
                    </button>
                    <form
                        action="{{ route('source-documents.review.reject', ['sourceDocument' => $sourceDocument, 'draft' => $draft->id]) }}"
                        method="POST"
                        class="inline"
                    >
                        @csrf
                        <button type="submit" class="btn-destructive">
                            Reject all
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Modal controller. Plain DOM, IIFE-scoped. Toggles open
             state via .is-open on the overlay; coordinates with the
             body's overflow to prevent background scrolling. --}}
        <script>
            (function () {
                const root = document.querySelector('[data-reject-modal]');
                const trigger = document.querySelector('[data-reject-trigger]');
                const backdrop = document.querySelector('[data-reject-backdrop]');
                const cancelBtn = document.querySelector('[data-reject-cancel]');

                if (!root || !trigger) return;

                function open() {
                    root.classList.add('is-open');
                    root.removeAttribute('inert');
                    document.body.style.overflow = 'hidden';
                }

                function close() {
                    root.classList.remove('is-open');
                    root.setAttribute('inert', '');
                    document.body.style.overflow = '';
                    trigger.focus();
                }

                trigger.addEventListener('click', open);
                backdrop?.addEventListener('click', close);
                cancelBtn?.addEventListener('click', close);

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && root.classList.contains('is-open')) {
                        close();
                    }
                });
            })();
        </script>
    @endif

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