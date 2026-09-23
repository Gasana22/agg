<?php

namespace App\Modules\Traceability\Http\Controllers;

use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Http\Resources\TraceBatchResource;
use App\Support\Database\AppendOnlyViolation;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BatchController
{
    public function __construct(private readonly Recorder $recorder) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.kind' => ['sometimes', Rule::in(BatchKind::values())],
            'filter.status' => ['sometimes', Rule::in(BatchStatus::values())],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['created_at', '-created_at', 'batch_code', '-batch_code'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];
        $sort = $data['sort'] ?? '-created_at';

        $batches = TraceBatch::query()
            ->when($filter['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('batch_code', 'like', '%'.strtoupper($v).'%')
                ->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($v).'%'])))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        return TraceBatchResource::collection($batches);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(BatchKind::manual())],
            'name' => ['nullable', 'string', 'max:150'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'unit' => ['required_with:quantity', 'nullable', 'string', 'max:20'],
            'occurred_at' => ['sometimes', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $batch = $this->recorder->createBatch(
            BatchKind::from($data['kind']),
            $data,
            array_filter(['occurred_at' => $data['occurred_at'] ?? null, 'payload' => array_filter(['notes' => $data['notes'] ?? null])]),
        );

        return (new TraceBatchResource($batch->refresh()))->response()->setStatusCode(201)
            ->header('Location', url("/api/v1/farms/{$batch->farm_id}/traceability/batches/{$batch->id}"));
    }

    public function show(string $farm, TraceBatch $batch): TraceBatchResource
    {
        return new TraceBatchResource($batch);
    }

    /** Batches are part of the permanent history (docs/06 §1 "Writes"). */
    public function destroy(): never
    {
        throw new AppendOnlyViolation;
    }

    public function link(Request $request, string $farm, TraceBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'parent_batch_id' => ['required', 'uuid'],
            'link_type' => ['required', Rule::in(LinkType::values())],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['required_with:quantity', 'nullable', 'string', 'max:20'],
        ]);

        // Resolved through the farm scope: another farm's batch is "not found".
        $parent = TraceBatch::find($data['parent_batch_id']);
        if ($parent === null) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
                'parent_batch_id' => ['The selected parent batch does not exist in this farm.'],
            ]);
        }

        $link = $this->recorder->link($parent, $batch, LinkType::from($data['link_type']), isset($data['quantity']) ? (string) $data['quantity'] : null, $data['unit'] ?? null);

        return new JsonResponse(['data' => [
            'id' => $link->id,
            'type' => 'trace_batch_link',
            'from' => $link->parent_batch_id,
            'to' => $link->child_batch_id,
            'link_type' => $link->link_type->value,
            'quantity' => $link->quantity,
            'unit' => $link->unit,
        ]], 201);
    }

    public function changeStatus(Request $request, string $farm, TraceBatch $batch): TraceBatchResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(BatchStatus::values())],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return new TraceBatchResource($this->recorder->changeStatus($batch, BatchStatus::from($data['status']), $data['reason']));
    }
}
