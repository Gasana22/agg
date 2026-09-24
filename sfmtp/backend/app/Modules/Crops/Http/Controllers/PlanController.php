<?php

namespace App\Modules\Crops\Http\Controllers;

use App\Modules\Crops\Application\CropAccess;
use App\Modules\Crops\Application\CropPlans;
use App\Modules\Crops\Domain\Enums\PlanStatus;
use App\Modules\Crops\Domain\Models\CropPlan;
use App\Modules\Crops\Http\Resources\PlanResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PlanController
{
    public function __construct(
        private readonly CropPlans $plans,
        private readonly CropAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(PlanStatus::values())],
            'filter.season_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        $plans = $this->withTotals(CropPlan::with(['crop', 'season']))
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['season_id'] ?? null, fn ($q, $v) => $q->where('season_id', $v))
            ->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        return PlanResource::collection($plans);
    }

    public function show(string $farm, CropPlan $cropPlan): PlanResource
    {
        return new PlanResource($this->withTotals(CropPlan::with(['crop', 'season']))->findOrFail($cropPlan->id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $this->access->assertNoMoneyUnlessAllowed($data, ['budget_amount']);
        $plan = $this->plans->create($data);

        return (new PlanResource($plan->load(['crop', 'season'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, CropPlan $cropPlan): PlanResource
    {
        OptimisticLock::check($request, $cropPlan);
        $data = $request->validate($this->rules(false));
        $this->access->assertNoMoneyUnlessAllowed($data, ['budget_amount']);

        return new PlanResource($this->plans->update($cropPlan, $data)->load(['crop', 'season']));
    }

    public function approve(string $farm, CropPlan $cropPlan): PlanResource
    {
        return new PlanResource($this->plans->approve($cropPlan)->load(['crop', 'season']));
    }

    public function close(string $farm, CropPlan $cropPlan): PlanResource
    {
        return new PlanResource($this->plans->close($cropPlan)->load(['crop', 'season']));
    }

    private function withTotals($query)
    {
        return $query->withCount('cycles')->withSum('cycles as planted_area_ha', 'area_ha');
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'season_id' => [$required, 'uuid'],
            'crop_id' => [$required, 'uuid'],
            'planned_area_ha' => [$required, 'numeric', 'gt:0', 'max:99999999'],
            'expected_yield' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999'],
            'yield_unit' => ['sometimes', 'string', 'exists:units,code'],
            'budget_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
