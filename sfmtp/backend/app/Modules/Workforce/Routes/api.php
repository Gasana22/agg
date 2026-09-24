<?php

use App\Modules\Workforce\Http\Controllers\ActivityController;
use App\Modules\Workforce\Http\Controllers\AttendanceController;
use App\Modules\Workforce\Http\Controllers\LeaveController;
use App\Modules\Workforce\Http\Controllers\MeController;
use App\Modules\Workforce\Http\Controllers\TaskController;
use App\Modules\Workforce\Http\Controllers\WorkerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.workforce.')
    ->whereUuid(['worker', 'activity', 'task', 'attendance', 'leave'])
    ->group(function () {
        Route::get('me/today', [MeController::class, 'today'])->name('me.today');

        Route::middleware('farm.can:workers.view')->group(function () {
            Route::get('workers', [WorkerController::class, 'index'])->name('workers.index');
            Route::get('workers/{worker}', [WorkerController::class, 'show'])->name('workers.show');
        });
        Route::middleware('farm.can:workers.manage')->group(function () {
            Route::post('workers', [WorkerController::class, 'store'])->name('workers.store');
            Route::patch('workers/{worker}', [WorkerController::class, 'update'])->name('workers.update');
        });
        Route::get('workers/{worker}/track', [AttendanceController::class, 'workerTrack'])->middleware('farm.can:worker.gps.view')->name('workers.track');

        Route::middleware('farm.can:tasks.view')->group(function () {
            Route::get('activities', [ActivityController::class, 'index'])->name('activities.index');
            Route::get('activities/{activity}', [ActivityController::class, 'show'])->name('activities.show');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        });
        Route::middleware('farm.can:tasks.manage')->group(function () {
            Route::post('activities', [ActivityController::class, 'store'])->name('activities.store');
            Route::patch('activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
            Route::post('activities/{activity}/assign', [ActivityController::class, 'assign'])->name('activities.assign');
            Route::post('activities/{activity}/cancel', [ActivityController::class, 'cancel'])->name('activities.cancel');
            Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
        });
        Route::middleware('farm.can:tasks.execute')->group(function () {
            foreach (['start', 'pause', 'resume', 'submit', 'note'] as $event) {
                Route::post("tasks/{task}/{$event}", [TaskController::class, 'step'])->defaults('step', $event)->name("tasks.{$event}");
            }
            Route::post('tasks/{task}/photos', [TaskController::class, 'photo'])->name('tasks.photos');
        });
        Route::middleware('farm.can:tasks.verify')->group(function () {
            Route::post('tasks/{task}/verify', [TaskController::class, 'verify'])->name('tasks.verify');
            Route::post('tasks/{task}/reject', [TaskController::class, 'reject'])->name('tasks.reject');
        });

        Route::get('attendance', [AttendanceController::class, 'index'])->middleware('farm.can:attendance.view')->name('attendance.index');
        Route::middleware('farm.can:attendance.record')->group(function () {
            Route::post('attendance/check-in', [AttendanceController::class, 'checkIn'])->name('attendance.check_in');
            Route::post('attendance/check-out', [AttendanceController::class, 'checkOut'])->name('attendance.check_out');
            Route::post('gps-points', [AttendanceController::class, 'track'])->name('gps.store');
        });
        Route::middleware('farm.can:attendance.approve')->group(function () {
            Route::post('attendance', [AttendanceController::class, 'store'])->name('attendance.store');
            Route::patch('attendance/{attendance}', [AttendanceController::class, 'update'])->name('attendance.update');
        });

        Route::middleware('farm.can:leave.request|leave.approve')->group(function () {
            Route::get('leave', [LeaveController::class, 'index'])->name('leave.index');
            Route::post('leave', [LeaveController::class, 'store'])->name('leave.store');
            Route::post('leave/{leave}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');
        });
        Route::middleware('farm.can:leave.approve')->group(function () {
            Route::post('leave/{leave}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
            Route::post('leave/{leave}/reject', [LeaveController::class, 'reject'])->name('leave.reject');
        });
    });
