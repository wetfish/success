<?php

use App\Http\Controllers\AccomplishmentController;
use App\Http\Controllers\CareerInputController;
use App\Http\Controllers\DraftMergeController;
use App\Http\Controllers\DraftReviewController;
use App\Http\Controllers\JobListingController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\LinkReviewController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ResumeDraftController;
use App\Http\Controllers\SourceDocumentController;
use App\Http\Controllers\TagAliasController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\PersonReviewController;
use App\Http\Controllers\TagReviewController;
use App\Http\Middleware\RequireLinkReviewComplete;
use App\Http\Middleware\RequirePersonReviewComplete;
use App\Http\Middleware\RequireTagReviewComplete;
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
/* Tag review wizard step. Pending tag review records (record_type='tag',
 * status='pending') get surfaced here for the user to accept / reject /
 * alias before entity-draft confirmation. Matched-at-derivation tag
 * records are auto-confirmed and don't appear in this UI — see
 * ReviewRecordExtractor::createTagReviewRecords.
 *
 * The accept/reject/alias endpoints return JSON (success: {ok: true},
 * failure: {error: '...'} with a 4xx/5xx status). The JS client treats
 * the response uniformly: parse JSON, branch on presence of error. No
 * partial HTML, no 204s — consistent contract for the JS to read. */
Route::get('source-documents/{sourceDocument}/review/tags', [TagReviewController::class, 'show'])
    ->name('source-documents.review.tags.show');

Route::post('source-documents/{sourceDocument}/review/tags/{record}/accept', [TagReviewController::class, 'accept'])
    ->name('source-documents.review.tags.accept');

Route::post('source-documents/{sourceDocument}/review/tags/{record}/reject', [TagReviewController::class, 'reject'])
    ->name('source-documents.review.tags.reject');

Route::post('source-documents/{sourceDocument}/review/tags/{record}/alias', [TagReviewController::class, 'alias'])
    ->name('source-documents.review.tags.alias');

/* Person review wizard step. Same shape as tag review minus the alias
 * action — people don't have aliases. Accept finds-or-creates a
 * catalog person; reject undoes our own creates. Slots between tag
 * review and entity-draft review in the wizard sequence. */
Route::get('source-documents/{sourceDocument}/review/people', [PersonReviewController::class, 'show'])
    ->name('source-documents.review.people.show');

Route::post('source-documents/{sourceDocument}/review/people/{record}/accept', [PersonReviewController::class, 'accept'])
    ->name('source-documents.review.people.accept');

Route::post('source-documents/{sourceDocument}/review/people/{record}/reject', [PersonReviewController::class, 'reject'])
    ->name('source-documents.review.people.reject');

/* Link review wizard step. Same shape as person review plus an update
 * action for editing link fields (url, type, title, description,
 * is_personal_appearance). Links are reviewed document-wide — the
 * URL-deduped review records span all entity drafts. */
Route::get('source-documents/{sourceDocument}/review/links', [LinkReviewController::class, 'show'])
    ->name('source-documents.review.links.show');

Route::post('source-documents/{sourceDocument}/review/links/{record}/accept', [LinkReviewController::class, 'accept'])
    ->name('source-documents.review.links.accept');

Route::post('source-documents/{sourceDocument}/review/links/{record}/reject', [LinkReviewController::class, 'reject'])
    ->name('source-documents.review.links.reject');

Route::post('source-documents/{sourceDocument}/review/links/{record}/update', [LinkReviewController::class, 'update'])
    ->name('source-documents.review.links.update');

Route::get('source-documents/{sourceDocument}/review', [DraftReviewController::class, 'index'])
    ->name('source-documents.review.index');

/* Entity-draft review routes are gated by all three review-complete
 * middlewares: RequireTagReviewComplete, RequirePersonReviewComplete,
 * and RequireLinkReviewComplete. If the source document has pending
 * review records for any step, hitting these URLs redirects to the
 * appropriate review page. Middlewares fire in list order (tag →
 * person → link), so the earliest incomplete step wins. */
Route::middleware([RequireTagReviewComplete::class, RequirePersonReviewComplete::class, RequireLinkReviewComplete::class])->group(function () {
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
});

/* Organization search and quick-create for the org picker.
 * Must be declared BEFORE Route::resource — otherwise
 * `organizations/search` matches `organizations/{organization}`
 * with "search" as the ID. Same pattern as tags.search and
 * people.search. */
Route::get('organizations/search', [OrganizationController::class, 'search'])
    ->name('organizations.search');

Route::post('organizations/quick-store', [OrganizationController::class, 'quickStore'])
    ->name('organizations.quick-store');

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

/* Tags are flat reference data shared across the application.
 * Unlike polymorphic entities (links), tags have a top-level CRUD
 * resource. No show page — the edit page serves double duty as
 * "view + manage" because aliases are managed inline there.
 *
 * Source-document tagging is deliberately not exposed here: tags
 * attached to source_documents are AI-populated during extraction
 * and surfaced through a dedicated review screen (coming in the
 * AI-pipeline-extension slice), not managed via this CRUD. */

/* The search endpoint must be declared BEFORE Route::resource —
 * otherwise `tags/search` matches the resource's `tags/{tag}`
 * pattern with "search" interpreted as a tag id, and route model
 * binding fails with a 404. */
Route::get('tags/search', [TagController::class, 'search'])
    ->name('tags.search');

Route::resource('tags', TagController::class)->except(['show']);

/* Aliases nest under a tag. Only store and destroy — aliases are
 * immutable once created (no edit form), and the management UI
 * lives inline on the parent tag's edit page rather than getting
 * its own index or show route. */
Route::post('tags/{tag}/aliases', [TagAliasController::class, 'store'])
    ->name('tag-aliases.store');

Route::delete('tags/{tag}/aliases/{alias}', [TagAliasController::class, 'destroy'])
    ->name('tag-aliases.destroy');

/* People are managers, collaborators, mentors, and other individuals
 * the user has worked with. Modeled once and attached to positions,
 * projects, and accomplishments via identically-shaped pivot tables
 * with role columns. See the schema doc's "People and connections"
 * section for the convergence rationale.
 *
 * Full resource — people have enough relationship surface (where
 * they appear as collaborators, which organization they're currently
 * at) to justify a dedicated show page, unlike tags. */

/* The search endpoint must be declared BEFORE Route::resource —
 * otherwise `people/search` matches the resource's `people/{person}`
 * pattern with "search" interpreted as a person id, and route model
 * binding fails with a 404. Same pattern as tags.search. */
Route::get('people/search', [PersonController::class, 'search'])
    ->name('people.search');

Route::resource('people', PersonController::class);

/* Job listings — entry point to the resume generation flow.
 * Top-level resource (not nested under organizations) because
 * the org picker on the form handles the association, and a
 * top-level route means users don't need to navigate into an
 * org first to create a listing. */
Route::resource('job-listings', JobListingController::class);

/* Resume draft wizard routes.
 * Three-screen wizard flow within the `selecting` status, followed
 * by draft generation and an editing phase:
 *
 *   Screen 1 — Strategy & requirements triage
 *     GET  /resume-drafts/{draft}                            → show (status router)
 *     POST /resume-drafts/{draft}/strategy                   → AJAX: save strategy
 *     POST /resume-drafts/{draft}/requirements/{req}/decide  → AJAX: accept/reject
 *
 *   Screen 2 — Per-requirement selection review
 *     GET  /resume-drafts/{draft}/requirements/{req}             → showRequirement
 *     POST /resume-drafts/{draft}/requirements/{req}/selections  → add catalog entry
 *     POST /resume-drafts/{draft}/requirements/{req}/experience  → submit freeform text
 *     POST /resume-drafts/{draft}/selections/{sel}/toggle        → AJAX: include/exclude
 *     POST /resume-drafts/{draft}/selections/{sel}/note          → AJAX: save relevance note
 *
 *   Screen 3 — Confirm & generate
 *     GET  /resume-drafts/{draft}/confirm                    → confirmPage
 *     POST /resume-drafts/{draft}/confirm                    → confirm (trigger generation)
 *
 *   Editing — Draft review & editing
 *     GET  /resume-drafts/{draft}/edit                       → edit (markdown editor)
 *     POST /resume-drafts/{draft}/content                    → updateContent (save edits)
 *     POST /resume-drafts/{draft}/revert                     → revert to AI original
 *     POST /resume-drafts/{draft}/approve                    → approve (advance status)
 *     POST /resume-drafts/{draft}/revise                     → reviseSelections (back to wizard)
 *
 * Entry point: POST from the job listing show page creates the draft
 * and redirects to Screen 1. */
Route::post('job-listings/{job_listing}/resume-drafts', [ResumeDraftController::class, 'create'])
    ->name('resume-drafts.create');

// Catalog search for adding entries to requirements on Screen 2.
// Registered before the {resume_draft} show route so "catalog-search"
// isn't captured as a resume_draft ID.
Route::get('resume-drafts/catalog-search', [ResumeDraftController::class, 'catalogSearch'])
    ->name('resume-drafts.catalog-search');

// Screen 1: Strategy & requirements triage.
Route::get('resume-drafts/{resume_draft}', [ResumeDraftController::class, 'show'])
    ->name('resume-drafts.show');

Route::post('resume-drafts/{resume_draft}/strategy', [ResumeDraftController::class, 'updateStrategy'])
    ->name('resume-drafts.update-strategy');

Route::post('resume-drafts/{resume_draft}/strategy/synthesize', [ResumeDraftController::class, 'synthesizeStrategy'])
    ->name('resume-drafts.synthesize-strategy');

Route::post('resume-drafts/{resume_draft}/requirements/{requirement}/decide', [ResumeDraftController::class, 'decideRequirement'])
    ->name('resume-drafts.decide-requirement');

// Screen 2: Per-requirement review.
Route::get('resume-drafts/{resume_draft}/requirements/{requirement}', [ResumeDraftController::class, 'showRequirement'])
    ->name('resume-drafts.requirement');

Route::post('resume-drafts/{resume_draft}/requirements/{requirement}/selections', [ResumeDraftController::class, 'addSelection'])
    ->name('resume-drafts.add-selection');

Route::post('resume-drafts/{resume_draft}/requirements/{requirement}/experience', [ResumeDraftController::class, 'submitExperience'])
    ->name('resume-drafts.submit-experience');

Route::post('resume-drafts/{resume_draft}/selections/{selection}/toggle', [ResumeDraftController::class, 'toggle'])
    ->name('resume-drafts.toggle');

Route::delete('resume-drafts/{resume_draft}/selections/{selection}', [ResumeDraftController::class, 'removeSelection'])
    ->name('resume-drafts.remove-selection');

Route::post('resume-drafts/{resume_draft}/selections/{selection}/note', [ResumeDraftController::class, 'updateNote'])
    ->name('resume-drafts.update-note');

// Screen 3: Confirm & generate.
Route::get('resume-drafts/{resume_draft}/confirm', [ResumeDraftController::class, 'confirmPage'])
    ->name('resume-drafts.confirm-page');

Route::post('resume-drafts/{resume_draft}/confirm', [ResumeDraftController::class, 'confirm'])
    ->name('resume-drafts.confirm');

// Editing: Draft review & editing.
Route::get('resume-drafts/{resume_draft}/edit', [ResumeDraftController::class, 'edit'])
    ->name('resume-drafts.edit');

Route::post('resume-drafts/{resume_draft}/content', [ResumeDraftController::class, 'updateContent'])
    ->name('resume-drafts.update-content');

Route::post('resume-drafts/{resume_draft}/revert', [ResumeDraftController::class, 'revert'])
    ->name('resume-drafts.revert');

Route::post('resume-drafts/{resume_draft}/approve', [ResumeDraftController::class, 'approve'])
    ->name('resume-drafts.approve');

Route::post('resume-drafts/{resume_draft}/revise', [ResumeDraftController::class, 'reviseSelections'])
    ->name('resume-drafts.revise-selections');