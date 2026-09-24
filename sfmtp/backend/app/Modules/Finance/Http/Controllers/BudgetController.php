<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\Budgets;
use App\Modules\Finance\Application\CostCenters;
use App\Modules\Finance\Domain\Models\Budget;
use App\Modules\Finance\Http\Resources\BudgetResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BudgetController
{
    public function __construct(private readonly Budgets $budgets) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate(['filter.status' => ['sometimes', Rule::in(['active', 'archived'])]])['filter']['status'] ?? 'active';

        return BudgetResource::collection(Budget::with(['lines.account', 'creator'])->where('status', $status)->orderByDesc('period_start')->limit(100)->get());
    }

    public function show(string $farm, Budget $budget): BudgetResource
    {
        return new BudgetResource($budget->load(['lines.account', 'creator']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        return (new BudgetResource($this->budgets->create($data)->load(['lines.account', 'creator'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, Budget $budget): BudgetResource
    {
        OptimisticLock::check($request, $budget);

        return new BudgetResource($this->budgets->update($budget, $request->validate($this->rules(false)))->load(['lines.account', 'creator']));
    }

    private function rules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return array_filter([
            'name' => [$r, 'string', 'min:2', 'max:150'],
            'period_start' => [$r, 'date'],
            'period_end' => [$r, 'date', ...($creating ? ['after_or_equal:period_start'] : [])],
            'scope_type' => $creating ? ['sometimes', 'nullable', Rule::in([...CostCenters::types(), 'general'])] : null,
            'scope_id' => $creating ? ['sometimes', 'nullable', 'uuid'] : null,
            'status' => $creating ? null : ['sometimes', Rule::in(['active', 'archived'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => [$r, 'array', 'min:1', 'max:100'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'version' => $creating ? null : ['sometimes', 'integer'],
        ]);
    }
}
