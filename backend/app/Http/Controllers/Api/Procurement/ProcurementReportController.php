<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ProcurementReportController extends Controller
{
    public function summary(Request $request, Farm $farm): JsonResponse
    {
        abort_unless($request->user()->canManageProcurement($farm), 403);

        $to = $request->query('to') ? Carbon::parse($request->query('to')) : now();
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : $to->copy()->startOfMonth();

        $orders = $farm->purchaseOrders()
            ->with(['items', 'payments'])
            ->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $totalOrdered = (float) $orders->sum(fn (PurchaseOrder $order) => $order->totalAmount());
        $totalPaid = (float) $orders->sum(fn (PurchaseOrder $order) => $order->totalPaid());

        $countsByStatus = collect(PurchaseOrderStatus::cases())
            ->mapWithKeys(fn (PurchaseOrderStatus $status) => [
                $status->value => $orders->where('status', $status)->count(),
            ]);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'order_count' => $orders->count(),
            'total_ordered' => round($totalOrdered, 2),
            'total_paid' => round($totalPaid, 2),
            'outstanding_balance' => round($totalOrdered - $totalPaid, 2),
            'orders_by_status' => $countsByStatus,
        ]);
    }
}
