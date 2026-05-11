<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSourceDocumentRequest;
use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Services\AiUsageTracker;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles the full source document submission flow:
 *
 *   store()    — accept either a pasted body or an uploaded file,
 *                create the SourceDocument, derive or generate a
 *                title, redirect to the preview page
 *   preview()  — show the saved body, the title, and the estimated
 *                extraction cost; user confirms or cancels
 *   extract()  — run the actual extraction, persist drafts, redirect
 *                to the show page
 *   show()     — read-only view of a previously submitted document
 *   file()     — stream an uploaded file (PDF) inline for embedding
 *                or as a download attachment via ?download=1
 *   destroy()  — cancel a pending submission, clean up any uploaded
 *                file from disk
 *
 * Extraction is synchronous. A spinner overlay in the view fills
 * the gap while the API call runs.
 *
 * Failures are soft: if title generation or extraction fail, we log
 * an AiUsageEvent with success=false but keep the SourceDocument
 * around. The user sees a flash message and can re-attempt later
 * (re-extraction UI is not in this slice).
 */
class SourceDocumentController extends Controller
{
    public function store(
        StoreSourceDocumentRequest $request,
        ExtractionProvider $provider,
        AiUsageTracker $tracker,
    ): RedirectResponse {
        $validated = $request->validated();

        // Decide which mode we're in. Validation has already guaranteed
        // exactly one of body/upload is present, so this branch is safe.
        if ($request->hasFile('upload')) {
            $document = $this->createDocumentFromFile(
                file: $request->file('upload'),
                validated: $validated,
            );
        } else {
            $document = SourceDocument::create([
                'body' => $validated['body'],
                'kind' => $validated['kind'] ?? 'other',
                'title' => $validated['title'] ?? null,
                'file_type' => 'text',
                'context_date' => $validated['context_date'] ?? null,
                'context_notes' => $validated['context_notes'] ?? null,
            ]);
        }

        // Generate a title via AI only for documents that came in
        // without one. File uploads always set a title from the
        // filename, so this branch only runs for pasted text.
        // Soft-fails — if the API errors, we log the failure and
        // leave title null. The show page falls back to "Untitled
        // document".
        if (! $document->title) {
            try {
                $summary = $provider->summarizeTitle($document->body);
                $document->update(['title' => $summary->title]);
                $tracker->recordSummary($summary, $provider->name(), $document);
            } catch (ExtractionException $e) {
                Log::warning('Title generation failed', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                ]);
                $tracker->recordFailure(
                    provider: $provider->name(),
                    model: 'unknown',
                    operation: 'summarize_title',
                    errorMessage: $e->getMessage(),
                    document: $document,
                );
            }
        }

        return redirect()->route('source-documents.preview', $document);
    }

    /**
     * Create a SourceDocument from an uploaded file. Branches on the
     * file's extension:
     *
     *   .pdf       → store the file at a UUID path on the local disk;
     *                body stays null; provider sends the file as base64
     *                at extraction time.
     *
     *   .txt / .md → read contents into the body column at upload time;
     *                no file is persisted (body is the canonical form
     *                for textual sources).
     *
     * Title is derived from the original filename in both cases:
     * extension stripped, underscores and hyphens converted to spaces.
     * The user can edit the title later (when edit support lands).
     */
    private function createDocumentFromFile(UploadedFile $file, array $validated): SourceDocument
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $originalName = $file->getClientOriginalName();

        // Strip extension and convert separators to spaces so the
        // title reads naturally. "Lightning_Labs-resume.pdf" becomes
        // "Lightning Labs resume".
        $titleFromFilename = preg_replace('/\.(pdf|txt|md)$/i', '', $originalName);
        $titleFromFilename = str_replace(['_', '-'], ' ', $titleFromFilename);
        $titleFromFilename = trim($titleFromFilename);

        // User-supplied title wins; otherwise the filename-derived
        // title; otherwise null (and AI title generation kicks in
        // for the body, but only the file-mode-with-empty-filename
        // case, which is essentially impossible for real uploads).
        $title = $validated['title'] ?? null;
        if ($title === null && $titleFromFilename !== '') {
            $title = $titleFromFilename;
        }

        $attributes = [
            'kind' => $validated['kind'] ?? 'other',
            'title' => $title,
            'context_date' => $validated['context_date'] ?? null,
            'context_notes' => $validated['context_notes'] ?? null,
        ];

        if ($extension === 'pdf') {
            // Store the PDF on the local disk at a UUID-based path.
            // The local disk roots at storage/app, which is private —
            // files there aren't web-accessible without a controller
            // action that streams them, which is what we want for
            // user-uploaded documents.
            $storedPath = $file->storeAs(
                'source-documents',
                Str::uuid() . '.pdf',
                'local',
            );

            $attributes['body'] = null;
            $attributes['file_type'] = 'pdf';
            $attributes['file_path'] = $storedPath;
        } else {
            // Text or markdown — read contents into the body column.
            // The original file is not retained.
            $attributes['body'] = $file->get();
            $attributes['file_type'] = $extension === 'md' ? 'markdown' : 'text';
            $attributes['file_path'] = null;
        }

        return SourceDocument::create($attributes);
    }

    public function preview(
        SourceDocument $sourceDocument,
        ExtractionProvider $provider,
    ): View|RedirectResponse {
        // Don't show the preview page for documents that have already
        // been extracted. Send the user to the show page instead.
        if ($sourceDocument->isCompleted()) {
            return redirect()
                ->route('source-documents.show', $sourceDocument)
                ->with('status', 'This document has already been extracted.');
        }

        // Estimate the extraction cost. If the estimate fails, fall
        // back to nulls — the preview page handles missing values
        // gracefully ("estimate unavailable").
        $estimatedTokens = null;
        $estimatedCostCents = null;

        try {
            $estimatedTokens = $provider->estimateTokens($sourceDocument);
            $estimatedCostCents = (int) round(
                $estimatedTokens * config('services.extraction.input_cost_per_mtok_cents') / 1_000_000
            );
        } catch (ExtractionException $e) {
            Log::warning('Token estimation failed', [
                'document_id' => $sourceDocument->id,
                'error' => $e->getMessage(),
            ]);
        }

        return view('source-documents.preview', [
            'sourceDocument' => $sourceDocument,
            'estimatedTokens' => $estimatedTokens,
            'estimatedCostCents' => $estimatedCostCents,
        ]);
    }

    public function extract(
        SourceDocument $sourceDocument,
        ExtractionProvider $provider,
        AiUsageTracker $tracker,
    ): RedirectResponse {
        if ($sourceDocument->isCompleted()) {
            return redirect()
                ->route('source-documents.show', $sourceDocument)
                ->with('status', 'This document has already been extracted.');
        }

        try {
            $result = $provider->extract($sourceDocument);
        } catch (ExtractionException $e) {
            Log::warning('Extraction failed', [
                'document_id' => $sourceDocument->id,
                'error' => $e->getMessage(),
            ]);
            $tracker->recordFailure(
                provider: $provider->name(),
                model: 'unknown',
                operation: 'extract_text',
                errorMessage: $e->getMessage(),
                document: $sourceDocument,
            );
            return redirect()
                ->route('source-documents.show', $sourceDocument)
                ->with('status', 'Extraction failed. Your document was saved but no records were extracted.');
        }

        $tracker->recordExtraction(
            result: $result,
            provider: $provider->name(),
            operation: 'extract_text',
            document: $sourceDocument,
        );

        foreach ($result->drafts as $draft) {
            ExtractedRecord::create([
                'source_document_id' => $sourceDocument->id,
                'record_type' => $draft->type,
                'payload' => $draft->data,
            ]);
        }

        return redirect()
            ->route('source-documents.show', $sourceDocument)
            ->with('status', "Extracted {$result->drafts->count()} draft records.");
    }

    public function show(SourceDocument $sourceDocument): View
    {
        // Count drafts by status so the show page can render the
        // review summary section. Uses a single grouped query rather
        // than four separate count() calls.
        $draftCounts = $sourceDocument
            ->extractedRecords()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return view('source-documents.show', [
            'sourceDocument' => $sourceDocument,
            'draftCounts' => [
                'pending' => (int) ($draftCounts['pending'] ?? 0),
                'confirmed' => (int) ($draftCounts['confirmed'] ?? 0),
                'rejected' => (int) ($draftCounts['rejected'] ?? 0),
                'merged' => (int) ($draftCounts['merged'] ?? 0),
                'total' => (int) $draftCounts->sum(),
            ],
        ]);
    }

    /**
     * Stream the uploaded file for a source document. Used by the
     * preview and show pages to embed PDFs inline via an iframe, and
     * to provide a download fallback for browsers that can't render
     * PDFs inline.
     *
     *   ?download=1   forces a download attachment (Content-Disposition:
     *                 attachment) using the document's title as the
     *                 filename. Without the flag, the file streams
     *                 inline so the browser renders it.
     *
     * Returns 404 if the document has no associated file (text-only
     * submissions and text/markdown uploads, where contents are in
     * the body column rather than on disk).
     */
    public function file(SourceDocument $sourceDocument, Request $request): StreamedResponse
    {
        if (! $sourceDocument->file_path) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($sourceDocument->file_path)) {
            abort(404);
        }

        if ($request->boolean('download')) {
            // Use the document's title as the download filename so the
            // user gets something meaningful in their downloads folder
            // rather than the UUID-based stored name. Fall back to a
            // generic name if title is null.
            $filename = ($sourceDocument->title ?: 'source-document') . '.pdf';
            return $disk->download($sourceDocument->file_path, $filename);
        }

        return $disk->response($sourceDocument->file_path);
    }

    public function destroy(SourceDocument $sourceDocument): RedirectResponse
    {
        // Clean up any uploaded file from disk before soft-deleting.
        // Without this, cancelled PDF uploads would accumulate as
        // orphaned files in storage/app/source-documents.
        if ($sourceDocument->file_path) {
            Storage::disk('local')->delete($sourceDocument->file_path);
        }

        $sourceDocument->delete();

        return redirect()
            ->route('career-input.index')
            ->with('status', 'Submission cancelled.');
    }
}