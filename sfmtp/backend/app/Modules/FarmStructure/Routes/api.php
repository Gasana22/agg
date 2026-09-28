<?php

use App\Modules\FarmStructure\Http\Controllers\NodeController;
use App\Modules\FarmStructure\Http\Controllers\SensorController;
use App\Modules\FarmStructure\Http\Controllers\SoilController;
use App\Modules\FarmStructure\Http\Controllers\StructureController;
use App\Modules\FarmStructure\Http\Controllers\WeatherController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}/structure')
    ->name('farms.structure.')
    ->whereUuid(['block', 'section', 'plot', 'location'])
    ->group(function () {
        Route::get('/', [StructureController::class, 'show'])->middleware('farm.can:structure.view')->name('show');

        foreach (['block', 'section', 'plot', 'location'] as $type) {
            $plural = $type.'s';

            Route::get("{$plural}/{{$type}}", [NodeController::class, 'show'])
                ->middleware('farm.can:structure.view')->defaults('type', $type)->name("{$plural}.show");

            Route::middleware('farm.can:structure.manage')->group(function () use ($type, $plural) {
                Route::post($plural, [NodeController::class, 'store'])->defaults('type', $type)->name("{$plural}.store");
                Route::patch("{$plural}/{{$type}}", [NodeController::class, 'update'])->defaults('type', $type)->name("{$plural}.update");
                Route::delete("{$plural}/{{$type}}", [NodeController::class, 'destroy'])->defaults('type', $type)->name("{$plural}.destroy");
            });
        }

        Route::put('plots/{plot}/soil', [SoilController::class, 'update'])
            ->middleware('farm.can:structure.soil.manage')->name('plots.soil.update');
    });

// The forecast at the farm, for every member (ADR-0018).
Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->get('farms/{farm}/weather', WeatherController::class)
    ->name('farms.weather');

// IoT extension point (ADR-0018): sensors on the farm map and their readings.
Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}/iot-devices')
    ->name('farms.iot-devices.')
    ->group(function () {
        Route::get('/', [SensorController::class, 'index'])->middleware('farm.can:structure.view')->name('index');
        Route::get('{iotDevice}/readings', [SensorController::class, 'readings'])->middleware('farm.can:structure.view')->name('readings');
        Route::middleware('farm.can:structure.manage')->group(function () {
            Route::post('/', [SensorController::class, 'store'])->name('store');
            Route::patch('{iotDevice}', [SensorController::class, 'update'])->name('update');
            Route::post('{iotDevice}/rotate-token', [SensorController::class, 'rotate'])->name('rotate-token');
        });
    });

// Devices post readings with their own token (no user session).
Route::middleware('throttle:120,1')->post('iot/readings', [SensorController::class, 'ingest'])->name('iot.readings');
