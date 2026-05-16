<?php

namespace App\Http\Middleware;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the wizard sequencing: entity-draft review routes are only
 * reachable once the document's link review step is complete.
 *
 * "Complete" means zero pending link review records for the document.
 *
 * Symmetric with RequireTagReviewComplete and RequirePersonReviewComplete.
 * All three middlewares apply to the entity-draft route group. The
 * order is: tag → person → link (matching route registration order),
 * so a document with pending records across multiple steps redirects
 * to the earliest incomplete step.
 */
class RequireLinkReviewComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $document = $request->route('sourceDocument');
        if (! $document instanceof SourceDocument) {
            return $next($request);
        }

        $hasPendingLinks = ExtractedRecord::query()
            ->where('source_document_id', $document->id)
            ->where('record_type', 'link')
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingLinks) {
            return redirect()->route('source-documents.review.links.show', $document)
                ->with('status', 'Review the document\'s links before continuing.');
        }

        return $next($request);
    }
}