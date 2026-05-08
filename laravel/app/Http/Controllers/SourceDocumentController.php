<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSourceDocumentRequest;
use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use App\Services\AiUsageTracker;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Handles the full source document submission flow:
 *
 *   store()    — accept a pasted body, create the SourceDocument,
 *                generate a title via AI, redirect to the preview page
 *   preview()  — show the saved body, the generated title, and the
 *                estimated extraction cost; user confirms or cancels
 *   extract()  — run the actual extraction, persist drafts, redirect
 *                to the show page
 *   show()     — read-only view of a previously submitted document
 *   destroy()  — cancel a pending submission from the preview page
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

        $document = SourceDocument::create([
            'body' => $validated['body'],
            'kind' => $validated['kind'] ?? 'other',
            'title' => $validated['title'] ?? null,
            'file_type' => 'text',
            'context_date' => $validated['context_date'] ?? null,
            'context_notes' => $validated['context_notes'] ?? null,
        ]);

        // Generate a title only if the user didn't supply one. Soft-fails
        // — if the API call errors, we log the failure and leave title
        // null. The show page falls back to "Untitled document".
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
        return view('source-documents.show', [
            'sourceDocument' => $sourceDocument,
        ]);
    }

    public function destroy(SourceDocument $sourceDocument): RedirectResponse
    {
        $sourceDocument->delete();

        return redirect()
            ->route('career-input.index')
            ->with('status', 'Submission cancelled.');
    }
}