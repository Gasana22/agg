<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\CostCenters;
use App\Modules\Finance\Application\Expenses;
use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Http\Resources\ExpenseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ExpenseController
{
    private const WITH = ['account', 'paidFrom', 'requester', 'decider'];

    public function __construct(private readonly Expenses $expenses, private readonly FinanceAccess $access) {}

    /** Finance sees every expense; requesters see their own. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:100'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date'],
            'filter.cost_center_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];

        return ExpenseResource::collection(Expense::with(self::WITH)
            ->when(! $this->access->can('finance.view'), fn ($q) => $q->where('requested_by', Auth::id()))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('spent_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('spent_on', '<=', $v))
            ->when($f['cost_center_id'] ?? null, fn ($q, $v) => $q->where('cost_center_id', $v))
            ->orderByDesc('spent_on')->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function show(string $farm, Expense $expense): ExpenseResource
    {
        $this->visible($expense);

        return new ExpenseResource($expense->load(self::WITH));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'payee' => ['sometimes', 'nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'min:3', 'max:300'],
            'cost_center_type' => ['sometimes', 'nullable', Rule::in([...CostCenters::types(), 'general'])],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid'],
            'paid_from_account_id' => ['sometimes', 'nullable', 'uuid'],
            'media_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return (new ExpenseResource($this->expenses->create($data)->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function approve(Request $request, string $farm, Expense $expense): ExpenseResource
    {
        $note = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']])['note'] ?? null;

        return new ExpenseResource($this->expenses->approve($expense, $note)->load(self::WITH));
    }

    public function reject(Request $request, string $farm, Expense $expense): ExpenseResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        return new ExpenseResource($this->expenses->reject($expense, $data['note'])->load(self::WITH));
    }

    public function cancel(string $farm, Expense $expense): ExpenseResource
    {
        $this->visible($expense);

        return new ExpenseResource($this->expenses->cancel($expense)->load(self::WITH));
    }

    public function void(Request $request, string $farm, Expense $expense): ExpenseResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new ExpenseResource($this->expenses->void($expense, $data['reason'])->load(self::WITH));
    }

    private function visible(Expense $expense): void
    {
        if (! $this->access->can('finance.view') && $expense->requested_by !== Auth::id()) {
            abort(404);
        }
    }
}
