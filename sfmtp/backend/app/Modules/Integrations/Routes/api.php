<?php

use App\Modules\Integrations\Http\Controllers\MapConfigController;
use App\Modules\Integrations\Http\Controllers\OnlinePaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])->group(function () {
    Route::get('map-config', MapConfigController::class)->name('map-config');
    Route::get('online-payments/{reference}', [OnlinePaymentController::class, 'show'])
        ->where('reference', 'SFMTP-[A-Z0-9]{14}')->name('online-payments.show');
});

// Payment gateway notifications: public, verified by the provider's signature.
Route::middleware('throttle:60,1')
    ->post('webhooks/payments/{provider}', [OnlinePaymentController::class, 'webhook'])
    ->where('provider', '[a-z_]+')->name('webhooks.payments');
