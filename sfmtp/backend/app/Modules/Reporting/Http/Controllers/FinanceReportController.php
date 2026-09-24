<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Finance\Application\CostCenters;
use App\Modules\Reporting\Application\FinanceReports;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Profit and loss, cash flow and cost per crop / animal group (reports.finance.view or finance.view). */
class FinanceReportController
{
    public function __construct(private readonly FinanceReports $reports, private readonly TenantContext $context) {}

    public function profitAndLoss(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'cost_center_type' => ['sometimes', 'nullable', Rule::in(CostCenters::types()), 'required_with:cost_center_id'],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid', 'required_with:cost_center_type'],
        ]);
        [$from, $to] = $this->period($data);

        return response()->json(['data' => $this->reports->profitAndLoss($from, $to, $data['cost_center_type'] ?? null, $data['cost_center_id'] ?? null)
            + ['monthly' => $this->reports->monthly()]]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from']]));

        return response()->json(['data' => $this->reports->cashFlow($from, $to) + ['forecast' => $this->reports->forecast()]]);
    }

    public function costPerCrop(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => $this->reports->cropCycles($data['from'] ?? null, $data['to'] ?? null)]);
    }

    public function costPerAnimalGroup(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => $this->reports->animalGroups($data['from'] ?? null, $data['to'] ?? null)]);
    }

    /** @return array{0: string, 1: string} this year to date by default */
    private function period(array $data): array
    {
        $now = CarbonImmutable::now($this->context->farm()->timezone);

        return [$data['from'] ?? $now->startOfYear()->toDateString(), $data['to'] ?? $now->toDateString()];
    }
}
