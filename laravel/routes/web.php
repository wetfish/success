<?php

use App\Http\Controllers\AccomplishmentController;
use App\Http\Controllers\CareerInputController;
use App\Http\Controllers\DraftReviewController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SourceDocumentController;
use Illuminate\Support\Facades\Route;

/* The home page is the AI extraction input. Users land here to paste
 * their career notes (file upload coming in a later slice). The form
 * submits to source-documents.store which creates the document and
 * generates a title, then redirects to the preview page where the
 * user reviews the cost estimate and confirms or cancels. */
Route::get('/', [CareerInputController::class, 'index'])->name('career-input.index');

/* Source document submission flow:
 *   POST /source-documents                          → create + generate title
 *   GET  /source-documents/{id}/preview             → cost preview page
 *   POST /source-documents/{id}/extract             → run extraction
 *   GET  /source-documents/{id}                     → read-only show
 *   GET  /source-documents/{id}/file                → serve uploaded file
 *                                                     (inline by default,
 *                                                     ?download=1 forces
 *                                                     attachment)
 *   DELETE /source-documents/{id}                   → cancel a pending submission
 *
 * Edit and re-extraction routes will come in later slices alongside
 * the draft review queue. */
Route::post('source-documents', [SourceDocumentController::class, 'store'])
    ->name('source-documents.store');

Route::get('source-documents/{sourceDocument}/preview', [SourceDocumentController::class, 'preview'])
    ->name('source-documents.preview');

Route::post('source-documents/{sourceDocument}/extract', [SourceDocumentController::class, 'extract'])
    ->name('source-documents.extract');

Route::get('source-documents/{sourceDocument}/file', [SourceDocumentController::class, 'file'])
    ->name('source-documents.file');

Route::get('source-documents/{sourceDocument}', [SourceDocumentController::class, 'show'])
    ->name('source-documents.show');

Route::delete('source-documents/{sourceDocument}', [SourceDocumentController::class, 'destroy'])
    ->name('source-documents.destroy');

/* Draft review queue:
 *   GET  /source-documents/{doc}/review               → redirect to the
 *                                                       first pending draft
 *                                                       (or back to show
 *                                                       if no pending)
 *   GET  /source-documents/{doc}/review/{draft}       → display a single
 *                                                       draft with prev/next
 *                                                       navigation
 *
 * Drafts are reviewed type-ordered: organizations → positions → projects →
 * accomplishments. Confirm/reject/merge actions are added in later mini-slices. */
Route::get('source-documents/{sourceDocument}/review', [DraftReviewController::class, 'index'])
    ->name('source-documents.review.index');

Route::get('source-documents/{sourceDocument}/review/{draft}', [DraftReviewController::class, 'show'])
    ->name('source-documents.review.show');

Route::resource('organizations', OrganizationController::class);

/* Positions are always created in the context of an organization. */
Route::get('organizations/{organization}/positions/create', [PositionController::class, 'create'])
    ->name('positions.create');

Route::resource('positions', PositionController::class)->except(['index', 'create']);

/* Projects have three create entry points. */
Route::get('organizations/{organization}/projects/create', [ProjectController::class, 'createForOrganization'])
    ->name('projects.createForOrganization');

Route::get('positions/{position}/projects/create', [ProjectController::class, 'createForPosition'])
    ->name('projects.createForPosition');

Route::get('projects/{project}/sub-projects/create', [ProjectController::class, 'createSubProject'])
    ->name('projects.createSubProject');

Route::resource('projects', ProjectController::class)->except(['index', 'create']);

/* Accomplishments have two create entry points: from a project (the
 * common case — accomplishments are evidence within projects) or
 * directly from a position (for things like promotions, mentoring, or
 * other role-level achievements that aren't tied to a discrete project). */
Route::get('projects/{project}/accomplishments/create', [AccomplishmentController::class, 'createForProject'])
    ->name('accomplishments.createForProject');

Route::get('positions/{position}/accomplishments/create', [AccomplishmentController::class, 'createForPosition'])
    ->name('accomplishments.createForPosition');

Route::resource('accomplishments', AccomplishmentController::class)->except(['index', 'create']);