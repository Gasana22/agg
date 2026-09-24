<?php

namespace App\Modules\Crops\Http\Controllers;

use App\Modules\Crops\Application\CropAccess;
use App\Modules\Crops\Application\CropOperations;
use App\Modules\Crops\Domain\Enums\OperationStatus;
use App\Modules\Crops\Domain\Enums\OperationType;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Domain\Models\CropOperation;
use App\Modules\Crops\Http\Resources\OperationResource;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OperationController
{
    private const WITH = ['inputs', 'cycle', 'recorder', 'verifier'];

    public function __construct(
        private readonly CropOperations $operations,
        private readonly CropAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.cycle_id' => ['sometimes', 'uuid'],
            'filter.status' => ['sometimes', Rule::in(OperationStatus::values())],
            'filter.type' => ['sometimes', Rule::in(OperationType::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        $operations = $this->access->scoped(CropOperation::with(self::WITH), 'crops.operations.view')
            ->when($filter['cycle_id'] ?? null, fn ($q, $v) => $q->where('cycle_id', $v))
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('occurred_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        return OperationResource::collection($operations);
    }

    public function show(string $farm, CropOperation $operation): OperationResource
    {
        $this->assertVisible($operation);

        return new OperationResource($operation->load(self::WITH));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::storeRules());

        $cycle = CropCycle::find($data['cycle_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
            'cycle_id' => ['The selected crop cycle does not exist in this farm.'],
        ]);

        $operation = $this->operations->record($cycle, $data);

        return (new OperationResource($operation->load(self::WITH)))->response()->setStatusCode(201);
    }

    /** Also used by offline sync (docs/08). */
    public static function storeRules(): array
    {
        return [
            'cycle_id' => ['required', 'uuid'],
            'type' => ['required', Rule::in(OperationType::values())],
            'occurred_at' => ['sometimes', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'observation_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'labour_hours' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'cost_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'inputs' => ['sometimes', 'array', 'max:20'],
            'inputs.*.product_name' => ['required', 'string', 'max:150'],
            'inputs.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'inputs.*.unit' => ['required', 'string', 'exists:units,code'],
            'inputs.*.withholding_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'inputs.*.input_batch_id' => ['nullable', 'uuid'],
        ];
    }

    public function verify(string $farm, CropOperation $operation): OperationResource
    {
        return new OperationResource($this->operations->verify($operation)->load(self::WITH));
    }

    public function reject(Request $request, string $farm, CropOperation $operation): OperationResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new OperationResource($this->operations->reject($operation, $data['reason'])->load(self::WITH));
    }

    private function assertVisible(CropOperation $operation): void
    {
        if (! $this->access->scoped(CropOperation::query(), 'crops.operations.view')->whereKey($operation->id)->exists()) {
            throw ApiException::notFound();
        }
    }
}
