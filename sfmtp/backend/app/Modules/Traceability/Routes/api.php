<?php

use App\Modules\Traceability\Http\Controllers\BatchController;
use App\Modules\Traceability\Http\Controllers\EventController;
use App\Modules\Traceability\Http\Controllers\IntegrityController;
use App\Modules\Traceability\Http\Controllers\JourneyController;
use App\Modules\Traceability\Http\Controllers\OperationController;
use App\Modules\Traceability\Http\Controllers\PublicTraceController;
use App\Modules\Traceability\Http\Controllers\PublishController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}/traceability')
    ->name('farms.traceability.')
    ->whereUuid(['batch', 'event', 'qrCode'])
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
            Route::get('batches/{batch}/approvals', [PublishController::class, 'approvals'])->name('batches.approvals.index');
            Route::get('batches/{batch}/qr-codes', [PublishController::class, 'batchCodes'])->name('batches.qr-codes.index');
            Route::get('qr-codes', [PublishController::class, 'index'])->name('qr-codes.index');
            Route::get('qr-codes/{qrCode}', [PublishController::class, 'show'])->name('qr-codes.show');
            Route::get('qr-codes/{qrCode}/image.svg', [PublishController::class, 'image'])->name('qr-codes.image');
            Route::get('qr-codes/{qrCode}/labels.pdf', [PublishController::class, 'labels'])->name('qr-codes.labels');
            Route::get('qr-stats', [PublishController::class, 'stats'])->name('qr-stats');
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
        // Publishing: what the public sees, and the codes that show it.
        Route::middleware('farm.can:trace.publish')->group(function () {
            Route::get('batches/{batch}/public-preview', [PublishController::class, 'preview'])->name('batches.public-preview');
            Route::post('batches/{batch}/approvals', [PublishController::class, 'approve'])->name('batches.approvals.store');
        });
        Route::middleware('farm.can:trace.publish|trace.qr.manage')->group(function () {
            Route::post('batches/{batch}/qr-codes', [PublishController::class, 'issue'])->name('batches.qr-codes.store');
            Route::post('qr-codes/{qrCode}/revoke', [PublishController::class, 'revoke'])->name('qr-codes.revoke');
        });
        Route::post('integrity/verify', [IntegrityController::class, 'verify'])->middleware('farm.can:trace.publish|audit.view')->name('integrity.verify');

        Route::middleware('farm.can:trace.events.create')->group(function () {
            Route::post('batches/{batch}/events', [EventController::class, 'store'])->name('batches.events.store');
            Route::post('events/{event}/corrections', [EventController::class, 'correct'])->name('events.corrections.store');
        });
    });

// Public QR scans: no sign-in, 60 requests a minute per IP (docs/06 §1).
Route::middleware('throttle:public')->group(function () {
    Route::get('public/trace/keys', [PublicTraceController::class, 'keys'])->name('public.trace.keys');
    Route::get('public/trace/{code}', [PublicTraceController::class, 'show'])->where('code', '[A-Za-z0-9-]{6,20}')->name('public.trace.show');
    Route::get('traceability/qr/{code}', [PublicTraceController::class, 'show'])->where('code', '[A-Za-z0-9-]{6,20}')->name('public.trace.alias');
});
