<?php

use App\Modules\Media\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

// Any member may upload; whoever links a file to a record is checked there.
Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.media.')
    ->whereUuid(['media'])
    ->group(function () {
        Route::post('media/uploads', [MediaController::class, 'upload'])->name('upload');
        Route::get('media/{media}', [MediaController::class, 'show'])->name('show');
        Route::get('media/{media}/content', [MediaController::class, 'content'])->name('content');
    });
