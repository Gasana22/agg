<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\CostCenters;
use App\Modules\Finance\Application\IncomeBook;
use App\Modules\Finance\Domain\Models\IncomeRecord;
use App\Modules\Finance\Http\Resources\IncomeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class IncomeController
{
    private const WITH = ['account', 'receivedInto', 'recorder'];

    public function __construct(private readonly IncomeBook $book) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(['recorded', 'void'])],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];

        return IncomeResource::collection(IncomeRecord::with(self::WITH)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('received_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('received_on', '<=', $v))
            ->orderByDesc('received_on')->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid'],
            'received_into_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'payer' => ['sometimes', 'nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'min:3', 'max:300'],
            'cost_center_type' => ['sometimes', 'nullable', Rule::in([...CostCenters::types(), 'general'])],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid'],
            'media_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return (new IncomeResource($this->book->record($data)->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function void(Request $request, string $farm, IncomeRecord $income): IncomeResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new IncomeResource($this->book->void($income, $data['reason'])->load(self::WITH));
    }
}
