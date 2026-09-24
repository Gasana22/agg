<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\Payables;
use App\Modules\Finance\Application\PaymentDesk;
use App\Modules\Finance\Domain\Models\Payment;
use App\Modules\Finance\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PaymentController
{
    public function __construct(private readonly PaymentDesk $desk, private readonly Payables $payables) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.direction' => ['sometimes', Rule::in(['in', 'out'])],
            'filter.payable_type' => ['sometimes', 'string', 'max:30'],
            'filter.payable_id' => ['sometimes', 'uuid'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];

        return PaymentResource::collection(Payment::with(['account', 'recorder'])
            ->when($f['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($f['payable_type'] ?? null, fn ($q, $v) => $q->where('payable_type', $v))
            ->when($f['payable_id'] ?? null, fn ($q, $v) => $q->where('payable_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('paid_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('paid_on', '<=', $v))
            ->orderByDesc('paid_on')->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payable_type' => ['required', Rule::in($this->payables->types())],
            'payable_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'paid_on' => ['sometimes', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(['cash', 'mobile_money', 'bank', 'cheque', 'other'])],
            'account_id' => ['required', 'uuid'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        return (new PaymentResource($this->desk->record($data)->load(['account', 'recorder'])))->response()->setStatusCode(201);
    }

    public function void(Request $request, string $farm, Payment $payment): PaymentResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new PaymentResource($this->desk->void($payment, $data['reason'])->load(['account', 'recorder']));
    }
}
