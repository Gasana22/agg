<?php

use App\Modules\Access\Http\Controllers\AcceptInvitationController;
use App\Modules\Access\Http\Controllers\InvitationController;
use App\Modules\Access\Http\Controllers\MemberController;
use App\Modules\Access\Http\Controllers\RoleController;
use App\Modules\Access\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])->group(function () {
    Route::get('me/workspaces', [WorkspaceController::class, 'index'])->name('me.workspaces');

    Route::prefix('farms/{farm}')->middleware('farm')->name('farms.')
        ->whereUuid(['member', 'role', 'invitation'])
        ->group(function () {
            Route::get('members', [MemberController::class, 'index'])->middleware('farm.can:members.view')->name('members.index');
            Route::middleware('farm.can:members.manage')->group(function () {
                Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
                Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
            });

            // members.manage, or members.invite_workers for field-worker roles (checked in Memberships).
            Route::get('invitations', [InvitationController::class, 'index'])->middleware('farm.can:members.view')->name('invitations.index');
            Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
            Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

            Route::get('roles', [RoleController::class, 'index'])->middleware('farm.can:roles.view')->name('roles.index');
            Route::get('permissions', [RoleController::class, 'permissions'])->middleware('farm.can:roles.view')->name('permissions.index');
            Route::middleware('farm.can:roles.manage')->group(function () {
                Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
                Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
                Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
                Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->name('roles.permissions.update');
            });
        });
});

// Public: the emailed invitation link. Accepting works signed in or not.
Route::middleware('throttle:public')->prefix('invitations/{token}')->name('invitations.')
    ->where(['token' => '[A-Za-z0-9]{48}'])
    ->group(function () {
        Route::get('/', [AcceptInvitationController::class, 'show'])->name('show');
        Route::post('accept', [AcceptInvitationController::class, 'accept'])->name('accept');
    });
