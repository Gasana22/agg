<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Procurement\Application\Purchasing;
use App\Modules\Procurement\Application\Receiving;
use App\Modules\Procurement\Application\SupplierInvoices;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\SupplierInvoice;
use App\Modules\Procurement\Http\Resources\PurchaseOrderResource;
use App\Modules\Procurement\Http\Resources\SupplierInvoiceResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController
{
    private const WITH = ['supplier', 'request', 'deliveryLocation', 'lines.item', 'creator', 'approver'];

    public function __construct(private readonly Purchasing $purchasing, private readonly Receiving $receiving) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.supplier_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];
        $statuses = isset($f['status']) ? explode(',', $f['status']) : null;

        return PurchaseOrderResource::collection(PurchaseOrder::with(['supplier', 'deliveryLocation', 'lines.item'])
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function show(string $farm, PurchaseOrder $order): PurchaseOrderResource
    {
        return new PurchaseOrderResource($order->load([...self::WITH, 'deliveries.lines.lot', 'deliveries.location', 'deliveries.receiver', 'invoices']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        return (new PurchaseOrderResource($this->purchasing->createOrder($data)->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, PurchaseOrder $order): PurchaseOrderResource
    {
        OptimisticLock::check($request, $order);

        return new PurchaseOrderResource($this->purchasing->updateOrder($order, $request->validate($this->rules(false)))->load(self::WITH));
    }

    public function approve(string $farm, PurchaseOrder $order): PurchaseOrderResource
    {
        return new PurchaseOrderResource($this->purchasing->approveOrder($order)->load(self::WITH));
    }

    public function send(string $farm, PurchaseOrder $order): PurchaseOrderResource
    {
        return new PurchaseOrderResource($this->purchasing->sendOrder($order)->load(self::WITH));
    }

    public function cancel(Request $request, string $farm, PurchaseOrder $order): PurchaseOrderResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return new PurchaseOrderResource($this->purchasing->cancelOrder($order, $data['reason'])->load(self::WITH));
    }

    public function close(string $farm, PurchaseOrder $order): PurchaseOrderResource
    {
        return new PurchaseOrderResource($this->purchasing->closeOrder($order)->load(self::WITH));
    }

    public function receive(Request $request, string $farm, PurchaseOrder $order): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'uuid'],
            'received_on' => ['sometimes', 'date'],
            'supplier_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'media_id' => ['sometimes', 'nullable', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.lot_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'lines.*.expires_on' => ['sometimes', 'nullable', 'date'],
        ]);
        $delivery = $this->receiving->receive($order, $data);

        return (new PurchaseOrderResource($order->refresh()->load([...self::WITH, 'deliveries.lines.lot', 'deliveries.location', 'deliveries.receiver', 'invoices'])))
            ->additional(['meta' => ['delivery' => ['id' => $delivery->id, 'code' => $delivery->code]]])
            ->response()->setStatusCode(201);
    }

    public function invoices(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['filter.supplier_id' => ['sometimes', 'uuid'], 'filter.order_id' => ['sometimes', 'uuid']]);
        $f = $data['filter'] ?? [];

        return SupplierInvoiceResource::collection(SupplierInvoice::with(['supplier', 'order', 'lines.orderLine.item', 'recorder'])
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($f['order_id'] ?? null, fn ($q, $v) => $q->where('order_id', $v))
            ->orderByDesc('invoice_date')->orderByDesc('created_at')->limit(200)->get());
    }

    public function invoice(Request $request, string $farm, PurchaseOrder $order): JsonResponse
    {
        $data = $request->validate([
            'invoice_number' => ['required', 'string', 'max:60'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'due_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:invoice_date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
        $invoice = $this->receiving->invoice($order, $data);

        return (new SupplierInvoiceResource($invoice->load(['supplier', 'order', 'lines.orderLine.item', 'recorder'])))->response()->setStatusCode(201);
    }

    public function cancelInvoice(Request $request, string $farm, SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $invoice = app(SupplierInvoices::class)->cancel($supplierInvoice, $data['reason']);

        return new SupplierInvoiceResource($invoice->load(['supplier', 'order', 'lines.orderLine.item', 'recorder']));
    }

    private function rules(bool $creating): array
    {
        return array_filter([
            'supplier_id' => $creating ? ['required', 'uuid'] : null,
            'purchase_request_id' => $creating ? ['sometimes', 'nullable', 'uuid'] : null,
            'expected_on' => ['sometimes', 'nullable', 'date'],
            'delivery_location_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ]);
    }
}
