<?php

use App\Modules\Parties\Http\Controllers\AcceptPortalInvitationController;
use App\Modules\Parties\Http\Controllers\PartyProfileController;
use App\Modules\Parties\Http\Controllers\PortalAccessController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])->group(function () {
    // Farm side: who can use the portals (the kind's manage permission is checked in PortalAccess).
    Route::prefix('farms/{farm}')->middleware('farm')->name('farms.')
        ->whereUuid(['portalInvitation', 'partyLink'])
        ->group(function () {
            Route::get('portal-access', [PortalAccessController::class, 'index'])->middleware('farm.can:suppliers.view|customers.view|suppliers.manage|customers.manage')->name('portal-access.index');
            Route::middleware('farm.can:suppliers.manage|customers.manage')->group(function () {
                Route::post('portal-invitations', [PortalAccessController::class, 'store'])->name('portal-invitations.store');
                Route::delete('portal-invitations/{portalInvitation}', [PortalAccessController::class, 'revokeInvitation'])->name('portal-invitations.destroy');
                Route::delete('portal-links/{partyLink}', [PortalAccessController::class, 'unlink'])->name('portal-links.destroy');
            });
        });

    // The party's own details, from either portal.
    Route::prefix('parties/{party}')->middleware('party')->name('parties.')->whereUuid('party')->group(function () {
        Route::get('profile', [PartyProfileController::class, 'show'])->name('profile.show');
        Route::patch('profile', [PartyProfileController::class, 'update'])->name('profile.update');
    });
});

// Public: the emailed invitation link. Accepting works signed in or not.
Route::middleware('throttle:public')->prefix('portal-invitations/{token}')->name('portal-invitations.')
    ->where(['token' => '[A-Za-z0-9]{48}'])
    ->group(function () {
        Route::get('/', [AcceptPortalInvitationController::class, 'show'])->name('show');
        Route::post('accept', [AcceptPortalInvitationController::class, 'accept'])->name('accept');
    });
