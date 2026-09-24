<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Inventory\Http\Resources\Refs;
use App\Modules\Sales\Application\SalesOrders;
use App\Modules\Sales\Domain\Models\SalesOrder;
use App\Modules\Sales\Http\Resources\ShipmentResource;
use App\Support\Http\ApiException;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Farm side of sales orders. Whoever only fulfils (the store) sees what to
 * send and to whom, never prices (ADR-0003).
 */
class SalesOrderController
{
    private const WITH = ['customer', 'lines.product', 'invoice', 'placer', 'approver', 'shipments.lines.batch'];

    public function __construct(private readonly SalesOrders $orders, private readonly FarmPermissions $permissions) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.customer_id' => ['sometimes', 'uuid'],
            'filter.source' => ['sometimes', Rule::in(['portal', 'internal'])],
        ]);
        $f = $data['filter'] ?? [];
        $rows = SalesOrder::with(['customer', 'lines', 'invoice'])
            ->when(isset($f['status']), fn ($q) => $q->whereIn('status', explode(',', $f['status'])))
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->orderByDesc('created_at')->limit(200)->get();

        return new JsonResponse(['data' => $rows->map(fn ($o) => $this->present($o))->values()]);
    }

    public function show(string $farm, string $salesOrder): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($this->find($salesOrder)->load(self::WITH), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['customer_id' => ['required', 'uuid']] + $this->rules(true));
        $order = $this->orders->place($data['customer_id'], $data, 'internal');

        return new JsonResponse(['data' => $this->present($order->load(self::WITH), true)], 201);
    }

    public function update(Request $request, string $farm, string $salesOrder): JsonResponse
    {
        $order = $this->find($salesOrder);
        OptimisticLock::check($request, $order);

        return $this->respond($this->orders->update($order, $request->validate($this->rules(false))));
    }

    public function approve(string $farm, string $salesOrder): JsonResponse
    {
        return $this->respond($this->orders->approve($this->find($salesOrder)));
    }

    public function reject(Request $request, string $farm, string $salesOrder): JsonResponse
    {
        $order = $this->find($salesOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return $this->respond($this->orders->reject($order, $data['reason']));
    }

    public function cancel(Request $request, string $farm, string $salesOrder): JsonResponse
    {
        $order = $this->find($salesOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return $this->respond($this->orders->cancel($order, $data['reason']));
    }

    public function invoice(string $farm, string $salesOrder): JsonResponse
    {
        return $this->respond($this->orders->invoice($this->find($salesOrder)), 201);
    }

    public function dispatch(Request $request, string $farm, string $salesOrder): JsonResponse
    {
        $order = $this->find($salesOrder);
        $data = $request->validate([
            'destination' => ['sometimes', 'nullable', 'string', 'max:300'],
            'vehicle' => ['sometimes', 'nullable', 'string', 'max:60'],
            'driver' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'dispatched_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.order_line_id' => ['required', 'uuid'],
            'lines.*.batch_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $shipment = $this->orders->dispatch($order, $data);

        return new JsonResponse([
            'data' => $this->present($order->refresh()->load(self::WITH), true),
            'meta' => ['shipment' => ['id' => $shipment->id, 'code' => $shipment->code]],
        ], 201);
    }

    private function respond(SalesOrder $order, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($order->refresh()->load(self::WITH), true)], $status);
    }

    private function find(string $id): SalesOrder
    {
        return SalesOrder::find($id) ?? throw ApiException::notFound();
    }

    /** @return array<string, mixed> */
    private function present(SalesOrder $o, bool $detail = false): array
    {
        $prices = collect(['sales.orders.create', 'sales.orders.approve', 'sales.invoice', 'sales.pricing.manage'])->contains(fn ($p) => $this->permissions->allows($p));
        $money = fn (array $a) => $prices ? $a : [];

        return [
            'id' => $o->id,
            'type' => 'sales_order',
            'code' => $o->code,
            'status' => $o->status,
            'source' => $o->source,
            'customer' => ['id' => $o->customer->id, 'code' => $o->customer->code, 'name' => $o->customer->name, 'on_portal' => $o->customer->party_id !== null],
            'requested_delivery_on' => $o->requested_delivery_on?->toDateString(),
            'delivery_address' => $o->delivery_address,
            'customer_note' => $o->customer_note,
            'internal_note' => $o->internal_note,
            'reject_reason' => $o->reject_reason,
            'cancel_reason' => $o->cancel_reason,
            'invoice' => $prices && $o->invoice ? ['id' => $o->invoice->id, 'code' => $o->invoice->code, 'status' => $o->invoice->status] : null,
            'approved_at' => $o->approved_at?->toIso8601ZuluString(),
            'delivered_at' => $o->delivered_at?->toIso8601ZuluString(),
            'created_at' => $o->created_at?->toIso8601ZuluString(),
            'version' => $o->version,
            'lines' => $o->lines->map(fn ($l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit' => $l->unit,
                'dispatched_quantity' => (float) $l->dispatched_quantity,
            ] + $money(['unit_price' => (float) $l->unit_price, 'amount' => (float) $l->amount]))->values(),
        ] + $money(['currency' => $o->currency, 'total_amount' => (float) $o->total_amount]) + ($detail ? [
            'placed_by' => Refs::user($o->placer),
            'approved_by' => Refs::user($o->approver),
            'shipments' => $o->shipments->sortBy('dispatched_at')->map(fn ($s) => (new ShipmentResource($s))->resolve())->values(),
        ] : []);
    }

    private function rules(bool $creating): array
    {
        return [
            'requested_delivery_on' => ['sometimes', 'nullable', 'date'],
            'delivery_address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'customer_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }
}
