<?php

use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PositionController;
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