<?php

use App\Modules\Billing\Http\Controllers\AdminPlanController;
use App\Modules\Billing\Http\Controllers\AdminSubscriptionController;
use App\Modules\Billing\Http\Controllers\OwnerBillingController;
use Illuminate\Support\Facades\Route;

// Farm owner (organization-level, works while farms are suspended).
Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])->prefix('billing')->name('billing.')->group(function () {
    Route::get('plans', [OwnerBillingController::class, 'plans'])->name('plans');
    Route::get('subscription', [OwnerBillingController::class, 'show'])->name('subscription');
    Route::post('subscription/change-plan', [OwnerBillingController::class, 'changePlan'])->name('subscription.change-plan');
    Route::post('subscription/cancel', [OwnerBillingController::class, 'cancel'])->name('subscription.cancel');
    Route::post('subscription/resume', [OwnerBillingController::class, 'resume'])->name('subscription.resume');
});

// Platform administration.
Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'platform.admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::middleware('platform.can:plans.manage')->group(function () {
        Route::get('plans', [AdminPlanController::class, 'index'])->name('plans.index');
        Route::post('plans', [AdminPlanController::class, 'store'])->name('plans.store');
        Route::patch('plans/{plan}', [AdminPlanController::class, 'update'])->whereUuid('plan')->name('plans.update');
    });

    Route::prefix('subscriptions')->name('subscriptions.')->whereUuid('subscription')->group(function () {
        Route::middleware('platform.can:subscriptions.view')->group(function () {
            Route::get('/', [AdminSubscriptionController::class, 'index'])->name('index');
            Route::get('{subscription}', [AdminSubscriptionController::class, 'show'])->name('show');
        });
        Route::middleware('platform.can:subscriptions.manage')->group(function () {
            Route::post('{subscription}/payments', [AdminSubscriptionController::class, 'recordPayment'])->name('payments.store');
            Route::post('{subscription}/change-plan', [AdminSubscriptionController::class, 'changePlan'])->name('change-plan');
            Route::post('{subscription}/cancel', [AdminSubscriptionController::class, 'cancel'])->name('cancel');
            Route::post('{subscription}/extend', [AdminSubscriptionController::class, 'extend'])->name('extend');
        });
    });
});
