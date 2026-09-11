<?php

use App\Http\Controllers\Api\AuthController;
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
    // Route::apiResource('farms', FarmController::class);

    // 3. Crop Management
    // Route::apiResource('crops', CropController::class);

    // 4. Livestock Management
    // Route::apiResource('livestock', LivestockController::class);

    // 5. Worker Management
    // Route::apiResource('workers', WorkerController::class);

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
