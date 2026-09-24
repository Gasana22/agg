<?php

use App\Modules\Traceability\Http\Controllers\BatchController;
use App\Modules\Traceability\Http\Controllers\EventController;
use App\Modules\Traceability\Http\Controllers\IntegrityController;
use App\Modules\Traceability\Http\Controllers\JourneyController;
use App\Modules\Traceability\Http\Controllers\OperationController;
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
            Route::get('batches/{batch}/timeline', [JourneyController::class, 'timeline'])->name('batches.timeline');
            Route::get('batches/{batch}/workers', [JourneyController::class, 'workers'])->name('batches.workers');
            Route::get('batches/{batch}/inputs', [JourneyController::class, 'inputs'])->name('batches.inputs');
            Route::get('batches/{batch}/sales', [JourneyController::class, 'sales'])->name('batches.sales');
            Route::get('batches/{batch}/locations', [JourneyController::class, 'locations'])->name('batches.locations');
            Route::get('alerts', [IntegrityController::class, 'alerts'])->name('alerts');
            Route::get('integrity', [IntegrityController::class, 'show'])->name('integrity');
        });

        Route::middleware('farm.can:trace.batches.create')->group(function () {
            Route::post('batches', [BatchController::class, 'store'])->name('batches.store');
            Route::post('batches/{batch}/links', [BatchController::class, 'link'])->name('batches.links.store');
            Route::post('batches/{batch}/status', [BatchController::class, 'changeStatus'])->name('batches.status');
            Route::delete('batches/{batch}', [BatchController::class, 'destroy'])->name('batches.destroy');
            Route::post('batches/{batch}/split', [OperationController::class, 'split'])->name('batches.split');
            Route::post('batches/{batch}/merge', [OperationController::class, 'merge'])->name('batches.merge');
            Route::post('batches/{batch}/process', [OperationController::class, 'process'])->name('batches.process');
            Route::post('batches/{batch}/package', [OperationController::class, 'package'])->name('batches.package');
        });

        // A recall reaches customers and revokes public codes: the people who publish decide it.
        Route::post('batches/{batch}/recall', [OperationController::class, 'recall'])->middleware('farm.can:trace.publish')->name('batches.recall');
        Route::post('integrity/verify', [IntegrityController::class, 'verify'])->middleware('farm.can:trace.publish|audit.view')->name('integrity.verify');

        Route::middleware('farm.can:trace.events.create')->group(function () {
            Route::post('batches/{batch}/events', [EventController::class, 'store'])->name('batches.events.store');
            Route::post('events/{event}/corrections', [EventController::class, 'correct'])->name('events.corrections.store');
        });
    });
