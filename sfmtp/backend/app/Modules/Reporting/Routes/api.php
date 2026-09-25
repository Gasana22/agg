<?php

use App\Modules\Reporting\Http\Controllers\AdminDashboardController;
use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Reporting\Http\Controllers\FinanceReportController;
use App\Modules\Reporting\Http\Controllers\HeatmapController;
use App\Modules\Reporting\Http\Controllers\MetricController;
use App\Modules\Reporting\Http\Controllers\MyFarmsController;
use App\Modules\Reporting\Http\Controllers\ReportController;
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

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}/metrics')
    ->name('farms.metrics.')
    ->where(['metric' => '[a-z_]+\\.[a-z_]+'])
    ->group(function () {
        Route::get('/', [MetricController::class, 'index'])->name('index');
        Route::get('{metric}', [MetricController::class, 'show'])->name('show');
    });

// Standard reports and queued exports (ADR-0017). Each report checks its
// own permissions; exports are visible to their requester only.
Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.')
    ->where(['report' => '[a-z_]+'])
    ->group(function () {
        Route::get('standard-reports', [ReportController::class, 'index'])->name('standard-reports.index');
        Route::get('standard-reports/{report}', [ReportController::class, 'show'])->name('standard-reports.show');
        Route::get('exports', [ReportController::class, 'exports'])->name('exports.index');
        Route::post('exports', [ReportController::class, 'export'])->name('exports.store');
        Route::get('exports/{exportId}', [ReportController::class, 'showExport'])->name('exports.show');
        Route::get('exports/{exportId}/download', [ReportController::class, 'download'])->name('exports.download');
    });

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm', 'farm.can:maps.view'])
    ->get('farms/{farm}/maps/activity', HeatmapController::class)
    ->name('farms.maps.activity');
