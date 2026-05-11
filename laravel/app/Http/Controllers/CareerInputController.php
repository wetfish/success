<?php

namespace App\Http\Controllers;

use App\Models\SourceDocument;
use Illuminate\View\View;

/**
 * The home page of the application. The primary action is the AI
 * extraction input — paste career notes or upload a file, hit submit,
 * and the back-end extracts structured records that the user reviews.
 *
 * The previous-submissions list shows each document with its pending
 * draft count so the user can see at a glance which submissions still
 * need attention. Clicking a row goes to the source document show
 * page, which surfaces the actual review entry point.
 */
class CareerInputController extends Controller
{
    public function index(): View
    {
        $sourceDocuments = SourceDocument::query()
            ->withCount([
                'extractedRecords as pending_drafts_count' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return view('career-input.index', [
            'sourceDocuments' => $sourceDocuments,
        ]);
    }
}