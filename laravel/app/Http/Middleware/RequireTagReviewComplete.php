<?php

namespace App\Http\Middleware;

use App\Models\ExtractedRecord;
use App\Models\SourceDocument;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the wizard sequencing: a source document's entity-draft
 * review routes are only reachable once the document's tag review
 * step is complete.
 *
 * "Complete" means zero pending tag review records for the document.
 * Matched-at-derivation tag records auto-confirm and don't count;
 * only records the user actually has to decide on (status=pending)
 * block the wizard from advancing.
 *
 * The redirect target is the tag review page itself, so the user
 * lands exactly where they need to be. Once they finish, advancement
 * is via in-page navigation (the "Next" button on the review page),
 * not by re-hitting the deep-link.
 *
 * The {sourceDocument} parameter is read from the route binding rather
 * than from the request input — by this point Laravel has already
 * resolved the model. If the parameter is somehow missing (a route
 * applied middleware but doesn't take a SourceDocument), the middleware
 * is a no-op and passes through; that's a route-registration bug, not
 * a runtime concern for this middleware to handle.
 */
class RequireTagReviewComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $document = $request->route('sourceDocument');
        if (! $document instanceof SourceDocument) {
            // Route doesn't bind a source document — pass through.
            return $next($request);
        }

        $hasPendingTags = ExtractedRecord::query()
            ->where('source_document_id', $document->id)
            ->where('record_type', 'tag')
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingTags) {
            return redirect()->route('source-documents.review.tags.show', $document)
                ->with('status', 'Review the document\'s tags before continuing.');
        }

        return $next($request);
    }
}