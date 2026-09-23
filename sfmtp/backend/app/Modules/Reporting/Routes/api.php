<?php

use App\Modules\Reporting\Http\Controllers\AdminDashboardController;
use App\Modules\Reporting\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}/dashboards/{dashboard}')
    ->name('farms.dashboards.')
    ->where(['dashboard' => '[a-z]+', 'widget' => '[a-z_]+'])
    ->group(function () {
        Route::get('/', [DashboardController::class, 'show'])->name('show');
        Route::get('widgets/{widget}', [DashboardController::class, 'widget'])->name('widget');
    });

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'platform.admin', 'platform.can:dashboard.view'])
    ->get('admin/dashboard', AdminDashboardController::class)
    ->name('admin.dashboard');
