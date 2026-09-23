<?php

use App\Modules\Traceability\Http\Controllers\BatchController;
use App\Modules\Traceability\Http\Controllers\EventController;
use App\Modules\Traceability\Http\Controllers\JourneyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}/traceability')
    ->name('farms.traceability.')
    ->whereUuid(['batch', 'event'])
    ->group(function () {
        Route::middleware('farm.can:trace.batches.view')->group(function () {
            Route::get('batches', [BatchController::class, 'index'])->name('batches.index');
            Route::get('batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
            Route::get('batches/{batch}/events', [EventController::class, 'index'])->name('batches.events.index');
            Route::get('batches/{batch}/journey', [JourneyController::class, 'show'])->name('batches.journey');
        });

        Route::middleware('farm.can:trace.batches.create')->group(function () {
            Route::post('batches', [BatchController::class, 'store'])->name('batches.store');
            Route::post('batches/{batch}/links', [BatchController::class, 'link'])->name('batches.links.store');
            Route::post('batches/{batch}/status', [BatchController::class, 'changeStatus'])->name('batches.status');
            Route::delete('batches/{batch}', [BatchController::class, 'destroy'])->name('batches.destroy');
        });

        Route::middleware('farm.can:trace.events.create')->group(function () {
            Route::post('batches/{batch}/events', [EventController::class, 'store'])->name('batches.events.store');
            Route::post('events/{event}/corrections', [EventController::class, 'correct'])->name('events.corrections.store');
        });
    });
