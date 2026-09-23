<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm', 'farm.can:audit.view'])
    ->get('farms/{farm}/audit-logs', [AuditLogController::class, 'index'])
    ->name('farms.audit-logs.index');
