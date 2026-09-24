<?php

namespace App\Modules\Traceability\Http\Controllers;

use App\Modules\Traceability\Application\BatchOperations;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Http\Resources\TraceBatchResource;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Split, merge, process, package and recall (docs/06 §4). */
class OperationController
{
    public function __construct(private readonly BatchOperations $operations) {}

    public function split(Request $request, string $farm, TraceBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'parts' => ['required', 'array', 'min:1', 'max:20'],
            'parts.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'parts.*.name' => ['nullable', 'string', 'max:150'],
        ] + $this->common());

        $parts = $this->operations->split($batch, $data['parts'], $this->event($data), $data['notes'] ?? null);

        return new JsonResponse(['data' => [
            'source' => new TraceBatchResource($batch->refresh()),
            'parts' => TraceBatchResource::collection(collect($parts)->map->refresh()),
        ]], 201);
    }

    public function merge(Request $request, string $farm, TraceBatch $batch): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:150']] + $this->inputRules(true) + $this->common());
        $output = $this->operations->merge($this->inputs($batch, $data), $data['name'] ?? null, $this->event($data), $data['notes'] ?? null);

        return $this->created($output);
    }

    public function process(Request $request, string $farm, TraceBatch $batch): JsonResponse
    {
        $data = $request->validate($this->outputRules() + [
            'output.method' => ['nullable', 'string', 'max:100'],
        ] + $this->inputRules(false) + $this->common());
        $output = $this->operations->process($this->inputs($batch, $data), $data['output'], $this->event($data), $data['notes'] ?? null);

        return $this->created($output);
    }

    public function package(Request $request, string $farm, TraceBatch $batch): JsonResponse
    {
        $data = $request->validate($this->outputRules() + [
            'output.package_count' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'output.package_size' => ['nullable', 'string', 'max:40'],
        ] + $this->inputRules(false) + $this->common());
        $output = $this->operations->package($this->inputs($batch, $data), $data['output'], $this->event($data), $data['notes'] ?? null);

        return $this->created($output);
    }

    public function recall(Request $request, string $farm, TraceBatch $batch): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $changed = $this->operations->recall($batch, $data['reason']);

        return new JsonResponse(['data' => [
            'batch' => new TraceBatchResource($batch->refresh()),
            'affected' => TraceBatchResource::collection(collect($changed)),
            'shipments' => collect($changed)->filter(fn (TraceBatch $b) => $b->kind->value === 'shipment')->count(),
        ]]);
    }

    /** @return array<string, array> */
    private function common(): array
    {
        return [
            'occurred_at' => ['sometimes', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, array> */
    private function inputRules(bool $required): array
    {
        return [
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
            'with' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:20'],
            'with.*.batch_id' => ['required', 'uuid', 'distinct'],
            'with.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
        ];
    }

    /** @return array<string, array> */
    private function outputRules(): array
    {
        return [
            'output' => ['required', 'array'],
            'output.name' => ['required', 'string', 'max:150'],
            'output.quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'output.unit' => ['required_with:output.quantity', 'nullable', 'string', 'max:20'],
        ];
    }

    /**
     * The route's batch first, then the others, each resolved through the
     * farm scope: another farm's batch is "not found".
     *
     * @return array<int, array{batch: TraceBatch, quantity: ?string}>
     */
    private function inputs(TraceBatch $batch, array $data): array
    {
        $inputs = [['batch' => $batch, 'quantity' => isset($data['quantity']) ? (string) $data['quantity'] : null]];
        foreach ($data['with'] ?? [] as $i => $other) {
            $b = TraceBatch::find($other['batch_id']);
            if ($b === null) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["with.{$i}.batch_id" => ['The selected batch does not exist in this farm.']]);
            }
            $inputs[] = ['batch' => $b, 'quantity' => isset($other['quantity']) ? (string) $other['quantity'] : null];
        }

        return $inputs;
    }

    private function event(array $data): array
    {
        return array_filter(['occurred_at' => $data['occurred_at'] ?? null]);
    }

    private function created(TraceBatch $batch): JsonResponse
    {
        return (new TraceBatchResource($batch->refresh()))->response()->setStatusCode(201)
            ->header('Location', url("/api/v1/farms/{$batch->farm_id}/traceability/batches/{$batch->id}"));
    }
}
