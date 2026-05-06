<?php

use App\Http\Controllers\AccomplishmentController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('organizations.index'));

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