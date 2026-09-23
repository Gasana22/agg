<?php

use App\Modules\FarmStructure\Http\Controllers\NodeController;
use App\Modules\FarmStructure\Http\Controllers\SoilController;
use App\Modules\FarmStructure\Http\Controllers\StructureController;
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
