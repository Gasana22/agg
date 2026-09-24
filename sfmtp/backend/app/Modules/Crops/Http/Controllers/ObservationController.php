<?php

namespace App\Modules\Crops\Http\Controllers;

use App\Modules\Crops\Application\CropAccess;
use App\Modules\Crops\Application\CropObservations;
use App\Modules\Crops\Domain\Enums\ObservationKind;
use App\Modules\Crops\Domain\Enums\ObservationStatus;
use App\Modules\Crops\Domain\Enums\Severity;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Domain\Models\CropObservation;
use App\Modules\Crops\Http\Resources\ObservationResource;
use App\Support\Http\ApiException;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ObservationController
{
    private const WITH = ['cycle', 'recorder'];

    public function __construct(
        private readonly CropObservations $observations,
        private readonly CropAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.cycle_id' => ['sometimes', 'uuid'],
            'filter.status' => ['sometimes', Rule::in([...ObservationStatus::values(), 'unresolved'])],
            'filter.kind' => ['sometimes', Rule::in(ObservationKind::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        $observations = $this->access->scoped(CropObservation::with(self::WITH)->withCount('treatments'), 'crops.operations.view')
            ->when($filter['cycle_id'] ?? null, fn ($q, $v) => $q->where('cycle_id', $v))
            ->when($filter['status'] ?? null, fn ($q, $v) => $v === 'unresolved' ? $q->where('status', '!=', ObservationStatus::Resolved->value) : $q->where('status', $v))
            ->when($filter['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->orderByDesc('observed_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        return ObservationResource::collection($observations);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::storeRules());

        $cycle = CropCycle::find($data['cycle_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
            'cycle_id' => ['The selected crop cycle does not exist in this farm.'],
        ]);
        unset($data['cycle_id']);

        return (new ObservationResource($this->observations->report($cycle, $data)->load(self::WITH)->loadCount('treatments')))->response()->setStatusCode(201);
    }

    /** Also used by offline sync (docs/08). */
    public static function storeRules(): array
    {
        return [
            'cycle_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(ObservationKind::values())],
            'severity' => ['required', Rule::in(Severity::values())],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'affected_pct' => ['nullable', 'numeric', 'between:0,100'],
            'observed_at' => ['sometimes', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    public function update(Request $request, string $farm, CropObservation $observation): ObservationResource
    {
        OptimisticLock::check($request, $observation);
        $data = $request->validate([
            'severity' => ['sometimes', Rule::in(Severity::values())],
            'status' => ['sometimes', Rule::in(ObservationStatus::values())],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'affected_pct' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return new ObservationResource($this->observations->update($observation, $data)->load(self::WITH)->loadCount('treatments'));
    }
}
