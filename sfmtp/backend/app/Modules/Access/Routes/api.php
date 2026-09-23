<?php

use App\Modules\Access\Http\Controllers\MemberController;
use App\Modules\Access\Http\Controllers\RoleController;
use App\Modules\Access\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])->group(function () {
    Route::get('me/workspaces', [WorkspaceController::class, 'index'])->name('me.workspaces');

    Route::prefix('farms/{farm}')->middleware('farm')->name('farms.')->group(function () {
        Route::get('members', [MemberController::class, 'index'])->middleware('farm.can:members.view')->name('members.index');
        Route::get('roles', [RoleController::class, 'index'])->middleware('farm.can:roles.view')->name('roles.index');
        Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions'])
            ->middleware('farm.can:roles.manage')->whereUuid('role')->name('roles.permissions.update');
        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('farm.can:roles.view')->name('permissions.index');
    });
});
