<?php

namespace App\Http\Controllers;

use App\Models\SourceDocument;
use Illuminate\View\View;

/**
 * Read-only viewing of source documents for now. Editing, deleting,
 * and triggering re-extraction will be added in later slices alongside
 * the draft review queue.
 */
class SourceDocumentController extends Controller
{
    public function show(SourceDocument $sourceDocument): View
    {
        return view('source-documents.show', [
            'sourceDocument' => $sourceDocument,
        ]);
    }
}