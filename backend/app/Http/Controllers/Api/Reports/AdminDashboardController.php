<?php

namespace App\Http\Controllers\Api\Reports;

use App\Enums\TraceBatchStatus;
use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\TraceBatch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-wide totals for the system_administrator — the role that
 * manages the system itself, not any one farm's operations. Nothing here
 * is farm-scoped; contrast with FarmDashboardController.
 */
class AdminDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSystemAdministrator(), 403);

        return response()->json([
            'farms' => [
                'total' => Farm::count(),
                'active' => Farm::where('is_active', true)->count(),
            ],
            'users' => [
                'total' => User::count(),
                'by_platform_role' => collect(RoleSeeder::ROLES)->mapWithKeys(
                    fn (string $role) => [$role => User::role($role)->count()]
                ),
            ],
            'trace_batches' => collect(TraceBatchStatus::cases())->mapWithKeys(
                fn (TraceBatchStatus $status) => [$status->value => TraceBatch::where('status', $status->value)->count()]
            )->put('total', TraceBatch::count()),
            'recent_farms' => Farm::with('owner')
                ->latest()
                ->take(5)
                ->get()
                ->map(fn (Farm $farm) => [
                    'id' => $farm->id,
                    'name' => $farm->name,
                    'owner' => $farm->owner?->name,
                    'created_at' => $farm->created_at,
                ]),
        ]);
    }
}
