<?php

namespace App\Http\Controllers\Api\Finance;

use App\Enums\PayrollPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AnimalHealthLog;
use App\Models\AnimalSale;
use App\Models\CropActivity;
use App\Models\CropSale;
use App\Models\DailyTask;
use App\Models\Farm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FinanceReportController extends Controller
{
    public function income(Request $request, Farm $farm): JsonResponse
    {
        abort_unless($request->user()->canManageFarm($farm), 403);

        [$from, $to] = $this->range($request);

        return response()->json([
            'from' => $from,
            'to' => $to,
            ...$this->computeIncome($farm, $from, $to),
        ]);
    }

    public function expenses(Request $request, Farm $farm): JsonResponse
    {
        abort_unless($request->user()->canManageFarm($farm), 403);

        [$from, $to] = $this->range($request);

        return response()->json([
            'from' => $from,
            'to' => $to,
            ...$this->computeExpenses($farm, $from, $to),
        ]);
    }

    public function profitAndLoss(Request $request, Farm $farm): JsonResponse
    {
        abort_unless($request->user()->canManageFarm($farm), 403);

        [$from, $to] = $this->range($request);

        $income = $this->computeIncome($farm, $from, $to);
        $expenses = $this->computeExpenses($farm, $from, $to);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'income_total' => $income['total'],
            'expenses_total' => $expenses['total'],
            'net_profit' => round($income['total'] - $expenses['total'], 2),
        ]);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function range(Request $request): array
    {
        $to = $request->query('to') ? Carbon::parse($request->query('to')) : now();
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : $to->copy()->startOfMonth();

        return [$from->toDateString(), $to->toDateString()];
    }

    private function computeIncome(Farm $farm, string $from, string $to): array
    {
        $cropSales = (float) CropSale::whereHas(
            'cropHarvest.cropSeason',
            fn ($q) => $q->where('farm_id', $farm->id)
        )
            ->whereBetween('sale_date', [$from, $to])
            ->get()
            ->sum(fn (CropSale $sale) => $sale->revenue());

        $livestockSales = (float) AnimalSale::whereHas(
            'animal',
            fn ($q) => $q->where('farm_id', $farm->id)
        )
            ->whereBetween('sale_date', [$from, $to])
            ->sum('sale_price');

        return [
            'crop_sales' => round($cropSales, 2),
            'livestock_sales' => round($livestockSales, 2),
            'total' => round($cropSales + $livestockSales, 2),
        ];
    }

    private function computeExpenses(Farm $farm, string $from, string $to): array
    {
        $cropOperations = (float) CropActivity::whereHas(
            'cropSeason',
            fn ($q) => $q->where('farm_id', $farm->id)
        )
            ->whereBetween('date', [$from, $to])
            ->sum('cost');

        $livestockCare = (float) AnimalHealthLog::whereHas(
            'animal',
            fn ($q) => $q->where('farm_id', $farm->id)
        )
            ->whereBetween('date', [$from, $to])
            ->sum('cost');

        $generalTasks = (float) DailyTask::where('farm_id', $farm->id)
            ->whereBetween('completed_at', [$from, $to.' 23:59:59'])
            ->sum('cost');

        $payroll = (float) $farm->payrollPayments()
            ->where('status', PayrollPaymentStatus::Paid->value)
            ->whereBetween('paid_date', [$from, $to])
            ->sum('net_amount');

        $otherExpenses = (float) $farm->expenses()
            ->whereBetween('date', [$from, $to])
            ->sum('amount');

        $total = $cropOperations + $livestockCare + $generalTasks + $payroll + $otherExpenses;

        return [
            'crop_operations' => round($cropOperations, 2),
            'livestock_care' => round($livestockCare, 2),
            'general_tasks' => round($generalTasks, 2),
            'payroll' => round($payroll, 2),
            'other_expenses' => round($otherExpenses, 2),
            'total' => round($total, 2),
        ];
    }
}
