<?php

namespace App\Modules\Crops\Http\Controllers;

use App\Modules\Crops\Application\CropCycles;
use App\Modules\Crops\Domain\Enums\CloseReason;
use App\Modules\Crops\Domain\Enums\CycleStage;
use App\Modules\Crops\Domain\Enums\ObservationStatus;
use App\Modules\Crops\Domain\Enums\PlantingMethod;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Http\Resources\CycleResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CycleController
{
    private const WITH = ['plot', 'crop', 'plan', 'season', 'harvests', 'cropLot', 'nurseryBatch', 'seedBatch'];

    public function __construct(private readonly CropCycles $cycles) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.stage' => ['sometimes', Rule::in([...CycleStage::values(), 'open'])],
            'filter.plot_id' => ['sometimes', 'uuid'],
            'filter.crop_id' => ['sometimes', 'uuid'],
            'filter.season_id' => ['sometimes', 'uuid'],
            'filter.plan_id' => ['sometimes', 'uuid'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        $cycles = $this->counted(CropCycle::with(self::WITH))
            ->when($filter['stage'] ?? null, fn ($q, $v) => $v === 'open' ? $q->where('stage', '!=', CycleStage::Closed->value) : $q->where('stage', $v))
            ->when($filter['plot_id'] ?? null, fn ($q, $v) => $q->where('plot_id', $v))
            ->when($filter['crop_id'] ?? null, fn ($q, $v) => $q->where('crop_id', $v))
            ->when($filter['season_id'] ?? null, fn ($q, $v) => $q->where('season_id', $v))
            ->when($filter['plan_id'] ?? null, fn ($q, $v) => $q->where('plan_id', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where('code', 'like', '%'.strtoupper($v).'%'))
            ->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        return CycleResource::collection($cycles);
    }

    public function show(string $farm, CropCycle $cycle): CycleResource
    {
        return new CycleResource($this->fresh($cycle));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plot_id' => ['required', 'uuid'],
            'crop_id' => ['required', 'uuid'],
            'plan_id' => ['nullable', 'uuid'],
            'season_id' => ['nullable', 'uuid', Rule::exists('crop_seasons', 'id')->where('farm_id', $request->route('farm'))],
            'planting_method' => ['sometimes', Rule::in(PlantingMethod::values())],
            'area_ha' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'planted_on' => ['nullable', 'date', 'before_or_equal:+1 day'],
            'sown_on' => ['nullable', 'date', 'before_or_equal:+1 day'],
            'expected_harvest_on' => ['nullable', 'date'],
            'expected_yield' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'yield_unit' => ['sometimes', 'string', 'exists:units,code'],
            'seeds_sown' => ['nullable', 'integer', 'min:0'],
            'seed_batch_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        [$cycle, $warnings] = $this->cycles->start($data);

        return (new CycleResource($this->fresh($cycle)))->additional(['meta' => ['warnings' => $warnings]])
            ->response()->setStatusCode(201)
            ->header('Location', url("/api/v1/farms/{$cycle->farm_id}/crop-cycles/{$cycle->id}"));
    }

    public function update(Request $request, string $farm, CropCycle $cycle): CycleResource
    {
        OptimisticLock::check($request, $cycle);
        $data = $request->validate([
            'expected_harvest_on' => ['sometimes', 'nullable', 'date'],
            'expected_yield' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999'],
            'yield_unit' => ['sometimes', 'string', 'exists:units,code'],
            'area_ha' => ['sometimes', 'numeric', 'gt:0', 'max:99999999'],
            'seedlings_germinated' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return new CycleResource($this->fresh($this->cycles->update($cycle, $data)));
    }

    public function transplant(Request $request, string $farm, CropCycle $cycle): CycleResource
    {
        $data = $request->validate([
            'planted_on' => ['required', 'date', 'before_or_equal:+1 day'],
            'seedlings_germinated' => ['nullable', 'integer', 'min:0'],
            'seedlings_transplanted' => ['nullable', 'integer', 'min:0'],
            'expected_harvest_on' => ['nullable', 'date', 'after_or_equal:planted_on'],
        ]);

        return new CycleResource($this->fresh($this->cycles->transplant($cycle, $data)));
    }

    public function stage(Request $request, string $farm, CropCycle $cycle): CycleResource
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in([CycleStage::Growing->value, CycleStage::Harvesting->value])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return new CycleResource($this->fresh($this->cycles->advance($cycle, CycleStage::from($data['stage']), $data['note'] ?? null)));
    }

    public function close(Request $request, string $farm, CropCycle $cycle): CycleResource
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(CloseReason::values())],
            'note' => ['nullable', 'string', 'max:500'],
            'closed_on' => ['nullable', 'date', 'before_or_equal:+1 day'],
        ]);

        return new CycleResource($this->fresh($this->cycles->close($cycle, CloseReason::from($data['reason']), $data['note'] ?? null, $data['closed_on'] ?? null)));
    }

    private function counted($query)
    {
        return $query->withCount([
            'operations',
            'observations as open_observations_count' => fn ($q) => $q->where('status', '!=', ObservationStatus::Resolved->value),
        ]);
    }

    private function fresh(CropCycle $cycle): CropCycle
    {
        return $this->counted(CropCycle::with(self::WITH))->findOrFail($cycle->id);
    }
}
