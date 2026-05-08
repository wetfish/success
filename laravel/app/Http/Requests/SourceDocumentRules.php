<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Shared validation rules for SourceDocument form requests. The
 * career-input form on the home page submits here. Accepts either
 * a pasted text body OR an uploaded file (.pdf, .txt, .md), but
 * not both — the user picks one mode at a time.
 *
 * Body is capped at 100,000 characters. That covers a generous
 * brag doc (roughly 30 pages of dense single-spaced text) without
 * letting a single submission consume an unreasonable amount of
 * the model's context window.
 *
 * Files are capped at 10MB. PDFs get persisted to local disk and
 * sent directly to Claude as base64 at extraction time. Text and
 * markdown files have their contents read into the body column
 * at upload time, so the file itself is not retained — body is
 * the canonical form for textual sources.
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

    /** Max file size in kilobytes — Laravel's `max` rule for files. */
    public const UPLOAD_MAX_KB = 10_240;

    /** Allowed file extensions for uploads. */
    public const UPLOAD_EXTENSIONS = ['pdf', 'txt', 'md'];

    public static function rules(): array
    {
        return [
            // Exactly one of body / upload is required. `required_without`
            // ensures at least one is present; `prohibits` ensures at most
            // one is present. Together they enforce "exactly one."
            'body' => [
                'required_without:upload',
                'prohibits:upload',
                'nullable',
                'string',
                'max:' . self::BODY_MAX_CHARS,
            ],
            'upload' => [
                'required_without:body',
                'prohibits:body',
                'nullable',
                'file',
                // Validate by the file's original extension rather than
                // by MIME-sniffed content. We never execute uploaded
                // files — they're either read as text into the body
                // column (for txt/md) or forwarded to Claude as base64
                // (for pdf) — so trusting the extension is sufficient.
                // This also avoids the inconsistency of the `mimes`
                // rule, which is strict for PDFs (real signature) but
                // loose for txt/md (both detected as text/plain), and
                // it works with UploadedFile::fake() in tests, which
                // produces files without real content signatures.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (! in_array($extension, self::UPLOAD_EXTENSIONS, true)) {
                        $allowed = implode(', ', self::UPLOAD_EXTENSIONS);
                        $fail("The {$attribute} must be one of: {$allowed}.");
                    }
                },
                'max:' . self::UPLOAD_MAX_KB,
            ],
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