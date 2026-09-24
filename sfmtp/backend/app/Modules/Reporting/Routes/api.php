<?php

use App\Modules\Reporting\Http\Controllers\AdminDashboardController;
use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Reporting\Http\Controllers\FinanceReportController;
use App\Modules\Reporting\Http\Controllers\MyFarmsController;
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

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])
    ->get('me/farms/overview', MyFarmsController::class)
    ->name('me.farms.overview');

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm', 'farm.can:reports.finance.view|finance.view'])
    ->prefix('farms/{farm}/reports')
    ->name('farms.reports.')
    ->group(function () {
        Route::get('profit-and-loss', [FinanceReportController::class, 'profitAndLoss'])->name('profit-and-loss');
        Route::get('cash-flow', [FinanceReportController::class, 'cashFlow'])->name('cash-flow');
        Route::get('cost-per-crop', [FinanceReportController::class, 'costPerCrop'])->name('cost-per-crop');
        Route::get('cost-per-animal-group', [FinanceReportController::class, 'costPerAnimalGroup'])->name('cost-per-animal-group');
    });
