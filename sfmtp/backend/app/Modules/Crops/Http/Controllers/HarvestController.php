<?php

namespace App\Modules\Crops\Http\Controllers;

use App\Modules\Crops\Application\CropHarvests;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Domain\Models\CropHarvest;
use App\Modules\Crops\Http\Resources\HarvestResource;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HarvestController
{
    private const WITH = ['cycle.crop', 'cycle.plot', 'batch', 'recorder'];

    public function __construct(private readonly CropHarvests $harvests) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.cycle_id' => ['sometimes', 'uuid'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        $harvests = CropHarvest::with(self::WITH)
            ->when($filter['cycle_id'] ?? null, fn ($q, $v) => $q->where('cycle_id', $v))
            ->when($filter['from'] ?? null, fn ($q, $v) => $q->where('harvested_on', '>=', $v))
            ->when($filter['to'] ?? null, fn ($q, $v) => $q->where('harvested_on', '<=', $v))
            ->orderByDesc('harvested_on')->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        return HarvestResource::collection($harvests);
    }

    public function show(string $farm, CropHarvest $harvest): HarvestResource
    {
        return new HarvestResource($harvest->load(self::WITH));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cycle_id' => ['required', 'uuid'],
            'harvested_on' => ['required', 'date', 'before_or_equal:+1 day'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'unit' => ['required', 'string', 'exists:units,code'],
            'quality_grade' => ['nullable', 'string', 'max:20'],
            'moisture_pct' => ['nullable', 'numeric', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'withholding_override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $cycle = CropCycle::find($data['cycle_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
            'cycle_id' => ['The selected crop cycle does not exist in this farm.'],
        ]);

        $harvest = $this->harvests->record($cycle, $data);

        return (new HarvestResource($harvest->load(self::WITH)))->response()->setStatusCode(201);
    }
}
