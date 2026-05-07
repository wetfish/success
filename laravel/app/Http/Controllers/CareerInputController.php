<?php

namespace App\Http\Controllers;

use App\Models\SourceDocument;
use Illuminate\View\View;

/**
 * The home page of the application. The primary action is the AI
 * extraction input — paste career notes or upload a file, hit submit,
 * and the back-end (slice 3) extracts structured records that the
 * user reviews (slice 4). This controller only renders the page; the
 * form submission handler comes in the next slice.
 */
class CareerInputController extends Controller
{
    public function index(): View
    {
        $sourceDocuments = SourceDocument::orderBy('created_at', 'desc')->get();

        return view('career-input.index', [
            'sourceDocuments' => $sourceDocuments,
        ]);
    }
}