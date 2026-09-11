<?php

namespace App\Http\Controllers\Api\Finance;

use App\Enums\AttendanceStatus;
use App\Enums\NotificationType;
use App\Enums\PayrollPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PayPayrollPaymentRequest;
use App\Http\Requests\Finance\StorePayrollPaymentRequest;
use App\Http\Resources\PayrollPaymentResource;
use App\Models\Notification;
use App\Models\PayrollPayment;
use App\Models\WorkerProfile;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PayrollPaymentController extends Controller
{
    public function index(WorkerProfile $workerProfile): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [PayrollPayment::class, $workerProfile]);

        return PayrollPaymentResource::collection(
            $workerProfile->payrollPayments()->with(['workerProfile.user', 'recorder'])->latest('period_start')->get()
        );
    }

    /**
     * Runs payroll for one worker over a period: days_worked is counted
     * from their approved attendance in that range, gross_amount is
     * days_worked * daily_rate. Nothing here is caller-supplied except
     * the period and an optional deduction, so the figures always trace
     * back to real attendance records.
     */
    public function store(StorePayrollPaymentRequest $request, WorkerProfile $workerProfile): PayrollPaymentResource
    {
        $this->authorize('create', [PayrollPayment::class, $workerProfile]);

        if ($workerProfile->daily_rate === null) {
            throw ValidationException::withMessages([
                'worker_profile' => 'This worker has no daily rate set — add one to their profile before running payroll.',
            ]);
        }

        $periodStart = $request->validated('period_start');
        $periodEnd = $request->validated('period_end');

        $daysWorked = $workerProfile->attendances()
            ->where('status', AttendanceStatus::Approved->value)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->count();

        $grossAmount = $daysWorked * (float) $workerProfile->daily_rate;
        $deductions = $request->validated('deductions') ?? 0;

        $payment = $workerProfile->payrollPayments()->create([
            'farm_id' => $workerProfile->farm_id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'days_worked' => $daysWorked,
            'gross_amount' => $grossAmount,
            'deductions' => $deductions,
            'net_amount' => $grossAmount - $deductions,
            'status' => 'pending',
            'recorded_by' => $request->user()->id,
        ]);

        return new PayrollPaymentResource($payment->load(['workerProfile.user', 'recorder']));
    }

    public function show(PayrollPayment $payrollPayment): PayrollPaymentResource
    {
        $this->authorize('view', $payrollPayment);

        return new PayrollPaymentResource($payrollPayment->load(['workerProfile.user', 'recorder']));
    }

    public function pay(PayPayrollPaymentRequest $request, PayrollPayment $payrollPayment): PayrollPaymentResource
    {
        $this->authorize('update', $payrollPayment);

        if ($payrollPayment->status === PayrollPaymentStatus::Paid) {
            throw ValidationException::withMessages([
                'status' => 'This payment has already been marked paid.',
            ]);
        }

        $deductions = $request->validated('deductions') ?? $payrollPayment->deductions;

        $payrollPayment->update([
            'deductions' => $deductions,
            'net_amount' => (float) $payrollPayment->gross_amount - (float) $deductions,
            'status' => 'paid',
            'paid_date' => now(),
        ]);

        $payrollPayment->load(['workerProfile.user', 'recorder']);

        if ($payrollPayment->workerProfile->user) {
            Notification::send(
                $payrollPayment->workerProfile->user,
                $payrollPayment->workerProfile->farm,
                NotificationType::PayrollPaid,
                'You were paid',
                "Net amount: {$payrollPayment->net_amount} for {$payrollPayment->period_start} to {$payrollPayment->period_end}.",
                $payrollPayment,
            );
        }

        return new PayrollPaymentResource($payrollPayment);
    }
}
