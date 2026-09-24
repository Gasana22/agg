<?php

namespace App\Modules\Sales\Http\Controllers\Portal;

use App\Modules\Media\Application\MediaStore;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Parties\Application\PartyContext;
use App\Modules\Parties\Domain\Models\PartyLink;
use App\Modules\Sales\Domain\Models\CustomerInvoice;
use App\Modules\Sales\Domain\Models\Shipment;
use App\Modules\Sales\Portal\CustomerPortal;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /customer/{party}/…: the customer portal (docs/05 §3.10). */
class CustomerPortalController
{
    public function __construct(
        private readonly CustomerPortal $portal,
        private readonly PartyContext $parties,
    ) {}

    public function dashboard(): JsonResponse
    {
        return new JsonResponse(['data' => ['type' => 'customer_dashboard'] + $this->portal->dashboard()]);
    }

    public function products(Request $request): JsonResponse
    {
        $data = $request->validate(['filter.farm_id' => ['sometimes', 'uuid']]);

        return new JsonResponse(['data' => $this->portal->products($data['filter']['farm_id'] ?? null)]);
    }

    public function photo(string $party, string $farm, string $product): Response
    {
        $model = $this->portal->product($product);
        $media = $model->media_id ? Media::find($model->media_id) : null;
        $bytes = $media ? app(MediaStore::class)->contents($media) : null;
        if ($bytes === null) {
            throw ApiException::notFound();
        }

        return response($bytes, 200, [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate(['filter.status' => ['sometimes', 'string', 'max:200']]);
        $statuses = isset($data['filter']['status']) ? explode(',', $data['filter']['status']) : null;
        $rows = array_merge(...$this->parties->eachFarm('customer', fn (PartyLink $link) => $this->portal->orders($link)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->orderByDesc('created_at')->limit(200)->get()
            ->map(fn ($o) => $this->portal->presentOrder($o, $link))->all()) ?: [[]]);
        usort($rows, fn ($a, $b) => strcmp($b['placed_at'] ?? '', $a['placed_at'] ?? ''));

        return new JsonResponse(['data' => $rows]);
    }

    public function show(string $party, string $farm, string $salesOrder): JsonResponse
    {
        $link = $this->parties->link();

        return new JsonResponse(['data' => $this->portal->presentOrder($this->portal->order($link, $salesOrder), $link, true)]);
    }

    public function place(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requested_delivery_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'delivery_address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ]);
        $link = $this->parties->link();
        $order = $this->portal->place($link, $data);

        return new JsonResponse(['data' => $this->portal->presentOrder($order, $link, true)], 201);
    }

    public function cancel(Request $request, string $party, string $farm, string $salesOrder): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $link = $this->parties->link();
        $model = $this->portal->cancel($this->portal->order($link, $salesOrder), $data['reason']);

        return new JsonResponse(['data' => $this->portal->presentOrder($model->refresh(), $link, true)]);
    }

    /** Invoices once issued (drafts stay with the farm), with what is paid and still due. */
    public function invoices(): JsonResponse
    {
        $rows = array_merge(...$this->parties->eachFarm('customer', fn (PartyLink $link) => CustomerInvoice::with('lines')
            ->where('customer_id', $link->record_id)->where('status', '!=', 'draft')->orderByDesc('invoice_date')->limit(200)->get()
            ->map(fn ($i) => ['type' => 'portal_customer_invoice', 'farm' => ['id' => $link->farm->id, 'name' => $link->farm->name], 'currency' => $link->farm->currency]
                + $this->portal->presentInvoice($i) + [
                    'lines' => $i->lines->map(fn ($l) => ['description' => $l->description, 'quantity' => (float) $l->quantity, 'unit' => $l->unit, 'unit_price' => (float) $l->unit_price, 'amount' => (float) $l->amount])->values()->all(),
                ])->all()) ?: [[]]);

        return new JsonResponse(['data' => $rows]);
    }

    public function deliveries(): JsonResponse
    {
        $rows = array_merge(...$this->parties->eachFarm('customer', fn (PartyLink $link) => Shipment::with('lines.batch')
            ->where('customer_id', $link->record_id)->orderByDesc('dispatched_at')->limit(200)->get()
            ->map(fn ($s) => $this->portal->presentShipment($s, $link))->all()) ?: [[]]);
        usort($rows, fn ($a, $b) => strcmp($b['dispatched_at'] ?? '', $a['dispatched_at'] ?? ''));

        return new JsonResponse(['data' => $rows]);
    }

    public function confirmDelivery(Request $request, string $party, string $farm, string $shipmentId): JsonResponse
    {
        $data = $request->validate([
            'received_by' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $link = $this->parties->link();

        return new JsonResponse(['data' => $this->portal->presentShipment($this->portal->confirmDelivery($link, $shipmentId, $data), $link)]);
    }

    /** Batches bought, with their approved public traceability. */
    public function purchases(): JsonResponse
    {
        return new JsonResponse(['data' => array_merge(...$this->parties->eachFarm('customer', fn (PartyLink $link) => $this->portal->purchases($link)) ?: [[]])]);
    }
}
