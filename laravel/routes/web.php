<?php

use App\Http\Controllers\AccomplishmentController;
use App\Http\Controllers\CareerInputController;
use App\Http\Controllers\DraftMergeController;
use App\Http\Controllers\DraftReviewController;
use App\Http\Controllers\LinkController;
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
 *   GET  /source-documents/{doc}/review                     → redirect to the
 *                                                             first pending draft
 *                                                             (or the first draft
 *                                                             overall if none
 *                                                             pending)
 *   GET  /source-documents/{doc}/review/{draft}             → display a single
 *                                                             draft with prev/next
 *                                                             navigation. All
 *                                                             drafts are browsable
 *                                                             regardless of status.
 *   POST .../review/{draft}/reject                          → reject + cascade
 *   POST .../review/{draft}/restore                         → restore rejected
 *                                                             draft to pending
 *   POST .../review/{draft}/confirm                         → create real catalog
 *                                                             record from draft
 *   GET  .../review/{draft}/merge                           → merge UI: candidate
 *                                                             picker when multiple
 *                                                             matches, side-by-side
 *                                                             editor when one is
 *                                                             resolved via the
 *                                                             ?candidate_id= query
 *                                                             param
 *   POST .../review/{draft}/merge/synthesize                → JSON endpoint for
 *                                                             on-demand text
 *                                                             synthesis. Logs an
 *                                                             AiUsageEvent
 *   POST .../review/{draft}/merge                           → execute the merge,
 *                                                             mark draft `merged`,
 *                                                             rewrite parent-name
 *                                                             references in pending
 *                                                             dependent drafts
 *
 * Drafts are reviewed type-ordered: organizations → positions → projects →
 * accomplishments. */
Route::get('source-documents/{sourceDocument}/review', [DraftReviewController::class, 'index'])
    ->name('source-documents.review.index');

Route::get('source-documents/{sourceDocument}/review/{draft}', [DraftReviewController::class, 'show'])
    ->name('source-documents.review.show');

Route::post('source-documents/{sourceDocument}/review/{draft}/reject', [DraftReviewController::class, 'reject'])
    ->name('source-documents.review.reject');

Route::post('source-documents/{sourceDocument}/review/{draft}/restore', [DraftReviewController::class, 'restore'])
    ->name('source-documents.review.restore');

Route::post('source-documents/{sourceDocument}/review/{draft}/confirm', [DraftReviewController::class, 'confirm'])
    ->name('source-documents.review.confirm');

Route::get('source-documents/{sourceDocument}/review/{draft}/merge', [DraftMergeController::class, 'show'])
    ->name('source-documents.review.merge.show');

Route::post('source-documents/{sourceDocument}/review/{draft}/merge/synthesize', [DraftMergeController::class, 'synthesize'])
    ->name('source-documents.review.merge.synthesize');

Route::post('source-documents/{sourceDocument}/review/{draft}/merge', [DraftMergeController::class, 'store'])
    ->name('source-documents.review.merge.store');

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

/* Links attach to multiple parent types (organizations, projects,
 * positions, accomplishments — and eventually people). Each parent
 * type gets a nested create-in-context route. Store is polymorphic:
 * the parent is resolved from hidden linkable_type and linkable_id
 * inputs at submission time. Edit, update, and destroy operate on
 * the link record directly via the flat `links/{link}` URL, with the
 * parent recovered from the link's `linkable` relationship rather
 * than being part of the URL.
 *
 * Links have no index and no show — they display inline on their
 * parent's show page via the `links._section` partial. */
Route::get('organizations/{organization}/links/create', [LinkController::class, 'createForOrganization'])
    ->name('links.createForOrganization');

Route::get('projects/{project}/links/create', [LinkController::class, 'createForProject'])
    ->name('links.createForProject');

Route::get('positions/{position}/links/create', [LinkController::class, 'createForPosition'])
    ->name('links.createForPosition');

Route::get('accomplishments/{accomplishment}/links/create', [LinkController::class, 'createForAccomplishment'])
    ->name('links.createForAccomplishment');

Route::resource('links', LinkController::class)->only(['store', 'edit', 'update', 'destroy']);