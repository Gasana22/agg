<?php

use App\Modules\Support\Http\Controllers\AdminTicketController;
use App\Modules\Support\Http\Controllers\MemberTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])->prefix('support/tickets')->name('support.tickets.')
    ->whereUuid(['ticket', 'grant'])
    ->group(function () {
        Route::get('/', [MemberTicketController::class, 'index'])->name('index');
        Route::post('/', [MemberTicketController::class, 'store'])->name('store');
        Route::get('{ticket}', [MemberTicketController::class, 'show'])->name('show');
        Route::post('{ticket}/messages', [MemberTicketController::class, 'reply'])->name('messages.store');
        Route::post('{ticket}/access-grants', [MemberTicketController::class, 'grantAccess'])->name('access-grants.store');
        Route::delete('{ticket}/access-grants/{grant}', [MemberTicketController::class, 'revokeAccess'])->name('access-grants.destroy');
    });

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'platform.admin'])->prefix('admin/support/tickets')->name('admin.support.tickets.')
    ->whereUuid('ticket')
    ->group(function () {
        Route::middleware('platform.can:support.view')->group(function () {
            Route::get('/', [AdminTicketController::class, 'index'])->name('index');
            Route::get('{ticket}', [AdminTicketController::class, 'show'])->name('show');
        });
        Route::middleware('platform.can:support.manage')->group(function () {
            Route::post('{ticket}/messages', [AdminTicketController::class, 'reply'])->name('messages.store');
            Route::patch('{ticket}', [AdminTicketController::class, 'update'])->name('update');
        });
    });
