<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\Payroll;
use App\Modules\Finance\Domain\Models\PayrollLine;
use App\Modules\Finance\Domain\Models\PayrollRun;
use App\Modules\Finance\Http\Resources\PayrollRunResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollController
{
    private const WITH = ['lines.worker', 'preparer', 'approver'];

    public function __construct(private readonly Payroll $payroll) {}

    public function index(): AnonymousResourceCollection
    {
        return PayrollRunResource::collection(PayrollRun::with(['preparer', 'approver'])->withCount('lines')->orderByDesc('period_start')->limit(100)->get());
    }

    public function show(string $farm, PayrollRun $payrollRun): PayrollRunResource
    {
        return new PayrollRunResource($payrollRun->load(self::WITH));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return (new PayrollRunResource($this->payroll->prepare($data)->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function recalculate(string $farm, PayrollRun $payrollRun): PayrollRunResource
    {
        return new PayrollRunResource($this->payroll->recalculate($payrollRun)->load(self::WITH));
    }

    public function updateLine(Request $request, string $farm, PayrollRun $payrollRun, PayrollLine $payrollLine): PayrollRunResource
    {
        $data = $request->validate([
            'bonus' => ['sometimes', 'numeric', 'min:0', 'max:999999999'],
            'deductions' => ['sometimes', 'numeric', 'min:0', 'max:999999999'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        return new PayrollRunResource($this->payroll->updateLine($payrollRun, $payrollLine, $data)->load(self::WITH));
    }

    public function approve(string $farm, PayrollRun $payrollRun): PayrollRunResource
    {
        return new PayrollRunResource($this->payroll->approve($payrollRun)->load(self::WITH));
    }

    public function cancel(Request $request, string $farm, PayrollRun $payrollRun): PayrollRunResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new PayrollRunResource($this->payroll->cancel($payrollRun, $data['reason'])->load(self::WITH));
    }
}
