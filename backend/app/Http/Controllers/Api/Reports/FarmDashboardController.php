<?php

namespace App\Http\Controllers\Api\Reports;

use App\Enums\AnimalStatus;
use App\Enums\AssetStatus;
use App\Enums\AttendanceStatus;
use App\Enums\CropSeasonStatus;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\AnimalHealthLog;
use App\Models\AnimalProductionRecord;
use App\Models\AssetMaintenanceLog;
use App\Models\CropHarvest;
use App\Models\Farm;
use App\Models\Plot;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A farm-level "at a glance" overview. Deliberately does not duplicate the
 * detailed reports that already exist (Finance income/expenses/P&L,
 * Procurement summary, Inventory low-stock) — those stay behind their own
 * endpoints and their own stricter authorization. This is a read-only
 * summary open to any farm member, so it carries only counts and alert
 * flags, never financial figures.
 */
class FarmDashboardController extends Controller
{
    public function show(Request $request, Farm $farm): JsonResponse
    {
        abort_unless($request->user()->canViewFarm($farm), 403);

        $monthStart = Carbon::now()->startOfMonth();
        $sevenDaysOut = Carbon::now()->addDays(7);

        return response()->json([
            'farm' => [
                'id' => $farm->id,
                'name' => $farm->name,
                'district' => $farm->district,
                'village' => $farm->village,
                'is_active' => $farm->is_active,
            ],
            'structure' => [
                'blocks_count' => $farm->blocks()->count(),
                'sections_count' => Section::whereHas('block', fn ($q) => $q->where('farm_id', $farm->id))->count(),
                'plots_count' => Plot::whereHas('section.block', fn ($q) => $q->where('farm_id', $farm->id))->count(),
            ],
            'workers' => [
                'active_count' => $farm->workerProfiles()->where('is_active', true)->count(),
                'checked_in_today' => $farm->workerProfiles()
                    ->whereHas('attendances', fn ($q) => $q->whereDate('date', Carbon::today())
                        ->whereIn('status', [AttendanceStatus::Pending->value, AttendanceStatus::Approved->value]))
                    ->count(),
            ],
            'crops' => [
                'active_seasons_count' => $farm->cropSeasons()
                    ->whereNotIn('status', [CropSeasonStatus::Harvested->value, CropSeasonStatus::Closed->value])
                    ->count(),
                'harvests_this_month_count' => CropHarvest::whereHas(
                    'cropSeason',
                    fn ($q) => $q->where('farm_id', $farm->id)
                )->where('harvest_date', '>=', $monthStart)->count(),
            ],
            'livestock' => [
                'animals_by_status' => collect(AnimalStatus::cases())->mapWithKeys(
                    fn (AnimalStatus $status) => [$status->value => $farm->animals()->where('status', $status->value)->count()]
                ),
                'production_records_this_month_count' => AnimalProductionRecord::whereHas(
                    'animal',
                    fn ($q) => $q->where('farm_id', $farm->id)
                )->where('date', '>=', $monthStart)->count(),
            ],
            'alerts' => [
                'low_stock_items_count' => $farm->inventoryItems()
                    ->whereNotNull('reorder_level')
                    ->get()
                    ->filter(fn ($item) => $item->isLowStock())
                    ->count(),
                'assets_under_maintenance_count' => $farm->assets()
                    ->where('status', AssetStatus::UnderMaintenance->value)
                    ->count(),
                'purchase_orders_outstanding_count' => $farm->purchaseOrders()
                    ->whereIn('status', [PurchaseOrderStatus::Ordered->value, PurchaseOrderStatus::PartiallyDelivered->value])
                    ->count(),
                'animal_health_follow_ups_due_count' => AnimalHealthLog::whereHas(
                    'animal',
                    fn ($q) => $q->where('farm_id', $farm->id)->where('status', AnimalStatus::Active->value)
                )->whereNotNull('next_due_date')->where('next_due_date', '<=', $sevenDaysOut)->count(),
                'asset_services_due_count' => AssetMaintenanceLog::whereHas(
                    'asset',
                    fn ($q) => $q->where('farm_id', $farm->id)->where('status', '!=', AssetStatus::Retired->value)
                )->whereNotNull('next_service_date')->where('next_service_date', '<=', $sevenDaysOut)->count(),
            ],
        ]);
    }
}
