<?php

use App\Modules\Catalog\Application\Catalogs;
use App\Modules\Catalog\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

$catalogs = implode('|', Catalogs::keys());

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])
    ->get('catalog/{catalog}', [CatalogController::class, 'index'])
    ->where('catalog', $catalogs)
    ->name('catalog.index');

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'platform.admin', 'platform.can:catalog.manage'])
    ->prefix('admin/catalog/{catalog}')
    ->where(['catalog' => $catalogs])
    ->name('admin.catalog.')
    ->group(function () {
        Route::get('/', [CatalogController::class, 'adminIndex'])->name('index');
        Route::post('/', [CatalogController::class, 'store'])->name('store');
        Route::patch('{id}', [CatalogController::class, 'update'])->whereUuid('id')->name('update');
    });
