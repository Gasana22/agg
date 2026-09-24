<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\Shipments;
use App\Modules\Sales\Domain\Models\Shipment;
use App\Modules\Sales\Http\Resources\ShipmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ShipmentController
{
    public function __construct(private readonly Shipments $shipments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(['dispatched', 'delivered', 'failed'])],
            'filter.customer_id' => ['sometimes', 'uuid'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        return ShipmentResource::collection(Shipment::with('customer', 'batch', 'invoice', 'lines.batch')
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('code', 'like', '%'.strtoupper($v).'%')
                ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($v).'%']))))
            ->orderByDesc('dispatched_at')->orderByDesc('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25)));
    }

    public function show(string $farm, Shipment $shipment): ShipmentResource
    {
        return new ShipmentResource($shipment->load('lines.batch', 'customer', 'invoice', 'batch'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'customer_invoice_id' => ['nullable', 'uuid'],
            'destination' => ['nullable', 'string', 'max:300'],
            'vehicle' => ['nullable', 'string', 'max:60'],
            'driver' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'dispatched_at' => ['sometimes', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.batch_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
            'lines.*.description' => ['nullable', 'string', 'max:300'],
        ]);
        $shipment = $this->shipments->dispatch($data);

        return (new ShipmentResource($shipment))->response()->setStatusCode(201)
            ->header('Location', url("/api/v1/farms/{$shipment->farm_id}/shipments/{$shipment->id}"));
    }

    public function deliver(Request $request, string $farm, Shipment $shipment): ShipmentResource
    {
        $data = $request->validate([
            'delivered_at' => ['sometimes', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'received_by' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return new ShipmentResource($this->shipments->deliver($shipment, $data));
    }

    public function fail(Request $request, string $farm, Shipment $shipment): ShipmentResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new ShipmentResource($this->shipments->fail($shipment, $data['reason']));
    }
}
