<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CropManagement\CropActivityController;
use App\Http\Controllers\Api\CropManagement\CropController;
use App\Http\Controllers\Api\CropManagement\CropHarvestController;
use App\Http\Controllers\Api\CropManagement\CropMonitoringLogController;
use App\Http\Controllers\Api\CropManagement\CropSaleController;
use App\Http\Controllers\Api\CropManagement\CropSeasonController;
use App\Http\Controllers\Api\FarmStructure\BlockController;
use App\Http\Controllers\Api\FarmStructure\FarmController;
use App\Http\Controllers\Api\FarmStructure\FarmMemberController;
use App\Http\Controllers\Api\FarmStructure\PlotController;
use App\Http\Controllers\Api\FarmStructure\SectionController;
use App\Http\Controllers\Api\WorkerManagement\AttendanceController;
use App\Http\Controllers\Api\WorkerManagement\DailyTaskController;
use App\Http\Controllers\Api\WorkerManagement\WorkerProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]);
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });
});

// Authenticated API surface. Each SFMTP module gets its own route group and
// controller namespace as it is built; scaffolding left here as landing spots.
Route::middleware('auth:api')->group(function () {
    // 2. Farm Structure Management (farm -> blocks -> sections -> plots)
    Route::apiResource('farms', FarmController::class);
    Route::apiResource('farms.blocks', BlockController::class)->shallow();
    Route::apiResource('blocks.sections', SectionController::class)->shallow();
    Route::apiResource('sections.plots', PlotController::class)->shallow();

    // Farm membership: who works this farm, and as what role. Prerequisite
    // for worker profiles below.
    Route::get('farms/{farm}/members', [FarmMemberController::class, 'index']);
    Route::post('farms/{farm}/members', [FarmMemberController::class, 'store']);
    Route::patch('farms/{farm}/members/{member}', [FarmMemberController::class, 'update']);
    Route::delete('farms/{farm}/members/{member}', [FarmMemberController::class, 'destroy']);

    // 3. Crop Management
    Route::get('farms/{farm}/crops', [CropController::class, 'index']);
    Route::post('farms/{farm}/crops', [CropController::class, 'store']);
    Route::get('crops/{crop}', [CropController::class, 'show']);
    Route::patch('crops/{crop}', [CropController::class, 'update']);
    Route::delete('crops/{crop}', [CropController::class, 'destroy']);

    Route::get('farms/{farm}/crop-seasons', [CropSeasonController::class, 'index']);
    Route::post('farms/{farm}/crop-seasons', [CropSeasonController::class, 'store']);
    Route::get('crop-seasons/{cropSeason}', [CropSeasonController::class, 'show']);
    Route::patch('crop-seasons/{cropSeason}', [CropSeasonController::class, 'update']);
    Route::delete('crop-seasons/{cropSeason}', [CropSeasonController::class, 'destroy']);

    Route::get('crop-seasons/{cropSeason}/activities', [CropActivityController::class, 'index']);
    Route::post('crop-seasons/{cropSeason}/activities', [CropActivityController::class, 'store']);
    Route::get('crop-activities/{cropActivity}', [CropActivityController::class, 'show']);
    Route::patch('crop-activities/{cropActivity}', [CropActivityController::class, 'update']);
    Route::delete('crop-activities/{cropActivity}', [CropActivityController::class, 'destroy']);

    Route::get('crop-seasons/{cropSeason}/monitoring-logs', [CropMonitoringLogController::class, 'index']);
    Route::post('crop-seasons/{cropSeason}/monitoring-logs', [CropMonitoringLogController::class, 'store']);
    Route::get('crop-monitoring-logs/{cropMonitoringLog}', [CropMonitoringLogController::class, 'show']);
    Route::patch('crop-monitoring-logs/{cropMonitoringLog}', [CropMonitoringLogController::class, 'update']);
    Route::delete('crop-monitoring-logs/{cropMonitoringLog}', [CropMonitoringLogController::class, 'destroy']);

    Route::get('crop-seasons/{cropSeason}/harvests', [CropHarvestController::class, 'index']);
    Route::post('crop-seasons/{cropSeason}/harvests', [CropHarvestController::class, 'store']);
    Route::get('crop-harvests/{cropHarvest}', [CropHarvestController::class, 'show']);
    Route::patch('crop-harvests/{cropHarvest}', [CropHarvestController::class, 'update']);
    Route::delete('crop-harvests/{cropHarvest}', [CropHarvestController::class, 'destroy']);

    Route::get('crop-harvests/{cropHarvest}/sales', [CropSaleController::class, 'index']);
    Route::post('crop-harvests/{cropHarvest}/sales', [CropSaleController::class, 'store']);
    Route::get('crop-sales/{cropSale}', [CropSaleController::class, 'show']);
    Route::patch('crop-sales/{cropSale}', [CropSaleController::class, 'update']);
    Route::delete('crop-sales/{cropSale}', [CropSaleController::class, 'destroy']);

    // 4. Livestock Management
    // Route::apiResource('livestock', LivestockController::class);

    // 5. Worker Management
    Route::get('farms/{farm}/worker-profiles', [WorkerProfileController::class, 'index']);
    Route::post('farms/{farm}/worker-profiles', [WorkerProfileController::class, 'store']);
    Route::get('worker-profiles/{workerProfile}', [WorkerProfileController::class, 'show']);
    Route::patch('worker-profiles/{workerProfile}', [WorkerProfileController::class, 'update']);
    Route::delete('worker-profiles/{workerProfile}', [WorkerProfileController::class, 'destroy']);

    Route::get('worker-profiles/{workerProfile}/attendances', [AttendanceController::class, 'index']);
    Route::post('worker-profiles/{workerProfile}/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('worker-profiles/{workerProfile}/check-out', [AttendanceController::class, 'checkOut']);
    Route::get('attendances/{attendance}', [AttendanceController::class, 'show']);
    Route::post('attendances/{attendance}/approve', [AttendanceController::class, 'approve']);

    Route::get('farms/{farm}/tasks', [DailyTaskController::class, 'index']);
    Route::post('farms/{farm}/tasks', [DailyTaskController::class, 'store']);
    Route::get('tasks/{dailyTask}', [DailyTaskController::class, 'show']);
    Route::patch('tasks/{dailyTask}', [DailyTaskController::class, 'update']);
    Route::patch('tasks/{dailyTask}/status', [DailyTaskController::class, 'updateStatus']);
    Route::delete('tasks/{dailyTask}', [DailyTaskController::class, 'destroy']);

    // 6. Activity Tracking
    // Route::apiResource('activities', ActivityController::class);

    // 7. Finance
    // Route::prefix('finance')->group(function () { ... });

    // 8. Procurement
    // Route::apiResource('purchase-orders', PurchaseOrderController::class);

    // 9. Inventory
    // Route::apiResource('inventory', InventoryController::class);

    // 10. Asset Management
    // Route::apiResource('assets', AssetController::class);

    // 11. Reports & Analytics
    // Route::prefix('reports')->group(function () { ... });

    // 16. Traceability & Chain of Custody
    // Route::apiResource('trace-batches', TraceBatchController::class);
    // Route::get('/trace/{batchId}/qr', [TraceabilityController::class, 'qr']);
});
