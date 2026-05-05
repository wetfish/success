<?php

use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('organizations.index'));

Route::resource('organizations', OrganizationController::class);

/* Positions are always created in the context of an organization, so
 * the create form route is nested under organizations. The remaining
 * routes (store, show, edit, update, destroy) are flat — once a
 * position exists, it has its own identity and doesn't need the
 * organization in the URL. */
Route::get('organizations/{organization}/positions/create', [PositionController::class, 'create'])
    ->name('positions.create');

Route::resource('positions', PositionController::class)->except(['index', 'create']);

/* Projects have three create entry points, depending on what the
 * project attaches to:
 *   - Organization-level (no position)
 *   - Position-level (attached to a specific role)
 *   - Sub-project of an existing project
 *
 * The contextual fields (organization_id, position_id, parent_project_id)
 * are auto-set based on which entry point was used. The user can edit
 * them later via the standard edit form. */
Route::get('organizations/{organization}/projects/create', [ProjectController::class, 'createForOrganization'])
    ->name('projects.createForOrganization');

Route::get('positions/{position}/projects/create', [ProjectController::class, 'createForPosition'])
    ->name('projects.createForPosition');

Route::get('projects/{project}/sub-projects/create', [ProjectController::class, 'createSubProject'])
    ->name('projects.createSubProject');

Route::resource('projects', ProjectController::class)->except(['index', 'create']);