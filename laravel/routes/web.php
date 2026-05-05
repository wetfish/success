<?php

use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('organizations.index'));

Route::resource('organizations', OrganizationController::class);