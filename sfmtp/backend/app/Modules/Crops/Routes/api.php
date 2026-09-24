<?php

use App\Modules\Crops\Http\Controllers\CycleController;
use App\Modules\Crops\Http\Controllers\HarvestController;
use App\Modules\Crops\Http\Controllers\ObservationController;
use App\Modules\Crops\Http\Controllers\OperationController;
use App\Modules\Crops\Http\Controllers\PlanController;
use App\Modules\Crops\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

// {crop_plan}, not {plan}: `plan` is bound to subscription plans (Billing).
Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.crops.')
    ->whereUuid(['crop', 'season', 'crop_plan', 'cycle', 'operation', 'observation', 'harvest'])
    ->group(function () {
        Route::middleware('farm.can:crops.plans.view')->group(function () {
            Route::get('crops', [SetupController::class, 'crops'])->name('crops.index');
            Route::get('seasons', [SetupController::class, 'seasons'])->name('seasons.index');
            Route::get('crop-plans', [PlanController::class, 'index'])->name('plans.index');
            Route::get('crop-plans/{crop_plan}', [PlanController::class, 'show'])->name('plans.show');
            Route::get('crop-cycles', [CycleController::class, 'index'])->name('cycles.index');
            Route::get('crop-cycles/{cycle}', [CycleController::class, 'show'])->name('cycles.show');
        });

        Route::middleware('farm.can:crops.plans.manage')->group(function () {
            Route::post('crops', [SetupController::class, 'storeCrop'])->name('crops.store');
            Route::patch('crops/{crop}', [SetupController::class, 'updateCrop'])->name('crops.update');
            Route::post('seasons', [SetupController::class, 'storeSeason'])->name('seasons.store');
            Route::patch('seasons/{season}', [SetupController::class, 'updateSeason'])->name('seasons.update');
            Route::post('crop-plans', [PlanController::class, 'store'])->name('plans.store');
            Route::patch('crop-plans/{crop_plan}', [PlanController::class, 'update'])->name('plans.update');
            Route::post('crop-plans/{crop_plan}/close', [PlanController::class, 'close'])->name('plans.close');
            Route::post('crop-cycles', [CycleController::class, 'store'])->name('cycles.store');
            Route::patch('crop-cycles/{cycle}', [CycleController::class, 'update'])->name('cycles.update');
            Route::post('crop-cycles/{cycle}/transplant', [CycleController::class, 'transplant'])->name('cycles.transplant');
            Route::post('crop-cycles/{cycle}/stage', [CycleController::class, 'stage'])->name('cycles.stage');
            Route::post('crop-cycles/{cycle}/close', [CycleController::class, 'close'])->name('cycles.close');
        });
        Route::post('crop-plans/{crop_plan}/approve', [PlanController::class, 'approve'])->middleware('farm.can:crops.plans.approve')->name('plans.approve');

        Route::middleware('farm.can:crops.operations.view')->group(function () {
            Route::get('crop-operations', [OperationController::class, 'index'])->name('operations.index');
            Route::get('crop-operations/{operation}', [OperationController::class, 'show'])->name('operations.show');
            Route::get('crop-observations', [ObservationController::class, 'index'])->name('observations.index');
        });
        Route::middleware('farm.can:crops.operations.record')->group(function () {
            Route::post('crop-operations', [OperationController::class, 'store'])->name('operations.store');
            Route::post('crop-observations', [ObservationController::class, 'store'])->name('observations.store');
            Route::patch('crop-observations/{observation}', [ObservationController::class, 'update'])->name('observations.update');
        });
        Route::middleware('farm.can:crops.operations.approve')->group(function () {
            Route::post('crop-operations/{operation}/verify', [OperationController::class, 'verify'])->name('operations.verify');
            Route::post('crop-operations/{operation}/reject', [OperationController::class, 'reject'])->name('operations.reject');
        });

        Route::middleware('farm.can:crops.harvest.view')->group(function () {
            Route::get('harvests', [HarvestController::class, 'index'])->name('harvests.index');
            Route::get('harvests/{harvest}', [HarvestController::class, 'show'])->name('harvests.show');
        });
        Route::post('harvests', [HarvestController::class, 'store'])->middleware('farm.can:crops.harvest.record')->name('harvests.store');
    });
