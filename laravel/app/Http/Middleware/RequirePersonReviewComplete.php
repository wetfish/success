<?php

namespace App\Http\Middleware;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the wizard sequencing: entity-draft review routes are only
 * reachable once the document's person review step is complete.
 *
 * "Complete" means zero pending person review records for the document.
 * Matched-at-derivation person records auto-confirm and don't count;
 * only records the user actually has to decide on (status=pending)
 * block the wizard from advancing.
 *
 * Symmetric with RequireTagReviewComplete. Both middlewares apply to
 * the entity-draft route group — a deep-link past either review step
 * redirects to whichever earlier step has pending records. The tag
 * middleware fires first (route registration order), so a document
 * with both pending tags and pending people redirects to tag review.
 */
class RequirePersonReviewComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $document = $request->route('sourceDocument');
        if (! $document instanceof SourceDocument) {
            return $next($request);
        }

        $hasPendingPeople = ExtractedRecord::query()
            ->where('source_document_id', $document->id)
            ->where('record_type', 'person')
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingPeople) {
            return redirect()->route('source-documents.review.people.show', $document)
                ->with('status', 'Review the document\'s people before continuing.');
        }

        return $next($request);
    }
}