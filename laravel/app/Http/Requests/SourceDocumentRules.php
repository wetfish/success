<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Shared validation rules for SourceDocument form requests. The
 * career-input form on the home page submits here. File upload
 * support comes in a later slice — for now only `body` (pasted
 * text) is accepted.
 *
 * Body is capped at 100,000 characters. That covers a generous
 * brag doc (roughly 30 pages of dense single-spaced text) without
 * letting a single submission consume an unreasonable amount of
 * the model's context window.
 */
class SourceDocumentRules
{
    public const KINDS = [
        'interview_prep',
        'performance_review',
        'brag_doc',
        'journal',
        'meeting_notes',
        'other',
    ];

    public const BODY_MAX_CHARS = 100_000;

    public static function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:' . self::BODY_MAX_CHARS],
            'kind' => ['nullable', 'string', Rule::in(self::KINDS)],
            'title' => ['nullable', 'string', 'max:255'],
            'context_date' => ['nullable', 'date'],
            'context_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Normalize raw form input. Trims strings and converts empty
     * strings to null on nullable fields so validation treats them
     * as absent.
     */
    public static function normalize(array $input): array
    {
        $cleaned = [];

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                // Don't trim body — line breaks and indentation are
                // meaningful in pasted notes. Only trim metadata fields.
                if ($key !== 'body') {
                    $value = trim($value);
                }
                if ($value === '') {
                    $value = null;
                }
            }
            $cleaned[$key] = $value;
        }

        return $cleaned;
    }
}