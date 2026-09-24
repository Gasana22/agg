<?php

use App\Modules\Livestock\Http\Controllers\AnimalController;
use App\Modules\Livestock\Http\Controllers\BreedingController;
use App\Modules\Livestock\Http\Controllers\GroupController;
use App\Modules\Livestock\Http\Controllers\MovementController;
use App\Modules\Livestock\Http\Controllers\RecordController;
use App\Modules\Livestock\Http\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.livestock.')
    ->whereUuid(['group', 'animal', 'health_record', 'feeding', 'weight', 'production', 'breeding', 'sale'])
    ->group(function () {
        Route::middleware('farm.can:livestock.animals.view')->group(function () {
            Route::get('animal-groups', [GroupController::class, 'index'])->name('groups.index');
            Route::get('animals', [AnimalController::class, 'index'])->name('animals.index');
            Route::get('animals/{animal}', [AnimalController::class, 'show'])->name('animals.show');
            Route::get('animals/{animal}/timeline', [AnimalController::class, 'timeline'])->name('animals.timeline');
            Route::get('animal-movements', [MovementController::class, 'index'])->name('movements.index');
            Route::get('animal-breedings', [BreedingController::class, 'index'])->name('breedings.index');
            Route::get('animal-sales', [SaleController::class, 'index'])->name('sales.index');
        });

        Route::middleware('farm.can:livestock.animals.manage')->group(function () {
            Route::post('animal-groups', [GroupController::class, 'store'])->name('groups.store');
            Route::patch('animal-groups/{group}', [GroupController::class, 'update'])->name('groups.update');
            Route::post('animals', [AnimalController::class, 'store'])->name('animals.store');
            Route::patch('animals/{animal}', [AnimalController::class, 'update'])->name('animals.update');
            Route::post('animals/{animal}/exit', [AnimalController::class, 'exit'])->name('animals.exit');
        });

        // Health, feeding, weights, production: one controller, the type as a route default.
        foreach (['health' => ['animal-health', 'health_record'], 'feeding' => ['animal-feedings', 'feeding'], 'weight' => ['animal-weights', 'weight'], 'production' => ['animal-production', 'production']] as $type => [$path, $param]) {
            Route::get($path, [RecordController::class, 'index'])->middleware('farm.can:livestock.animals.view')->defaults('type', $type)->name("{$type}.index");
            Route::middleware('farm.can:livestock.records.record')->group(function () use ($type, $path, $param) {
                Route::post($path, [RecordController::class, 'store'])->defaults('type', $type)->name("{$type}.store");
                Route::post("{$path}/{{$param}}/void", [RecordController::class, 'void'])->defaults('type', $type)->defaults('param', $param)->name("{$type}.void");
            });
        }

        Route::middleware('farm.can:livestock.records.record')->group(function () {
            Route::post('animal-movements', [MovementController::class, 'store'])->name('movements.store');
            Route::post('animal-breedings', [BreedingController::class, 'store'])->name('breedings.store');
            Route::patch('animal-breedings/{breeding}', [BreedingController::class, 'update'])->name('breedings.update');
            Route::post('animal-breedings/{breeding}/birth', [BreedingController::class, 'birth'])->name('breedings.birth');
        });

        Route::post('animal-sales', [SaleController::class, 'store'])->middleware('farm.can:livestock.sales.request')->name('sales.store');
        Route::middleware('farm.can:livestock.sales.approve')->group(function () {
            Route::post('animal-sales/{sale}/approve', [SaleController::class, 'approve'])->name('sales.approve');
            Route::post('animal-sales/{sale}/reject', [SaleController::class, 'reject'])->name('sales.reject');
            Route::post('animal-sales/{sale}/complete', [SaleController::class, 'complete'])->name('sales.complete');
        });
    });
