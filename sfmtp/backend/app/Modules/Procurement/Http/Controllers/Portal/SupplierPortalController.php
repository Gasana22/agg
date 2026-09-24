<?php

namespace App\Modules\Procurement\Http\Controllers\Portal;

use App\Modules\Media\Application\MediaStore;
use App\Modules\Parties\Application\PartyContext;
use App\Modules\Parties\Domain\Models\PartyLink;
use App\Modules\Procurement\Domain\Models\SupplierInvoice;
use App\Modules\Procurement\Domain\Models\SupplierInvoiceSubmission;
use App\Modules\Procurement\Portal\SupplierPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /supplier/{party}/…: the supplier portal (docs/05 §3.9). */
class SupplierPortalController
{
    public function __construct(
        private readonly SupplierPortal $portal,
        private readonly PartyContext $parties,
    ) {}

    public function dashboard(): JsonResponse
    {
        return new JsonResponse(['data' => ['type' => 'supplier_dashboard'] + $this->portal->dashboard()]);
    }

    /** Orders from every linked farm, newest first. */
    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate(['filter.status' => ['sometimes', 'string', 'max:200'], 'filter.farm_id' => ['sometimes', 'uuid']]);
        $statuses = isset($data['filter']['status']) ? explode(',', $data['filter']['status']) : null;
        $farm = $data['filter']['farm_id'] ?? null;

        $rows = array_merge(...$this->parties->eachFarm('supplier', fn (PartyLink $link) => $farm !== null && $link->farm_id !== $farm ? [] : $this->portal->orders($link)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->with(['lines.item', 'deliveryLocation'])->orderByDesc('sent_at')->limit(200)->get()
            ->map(fn ($o) => $this->portal->present($o, $link))->all()) ?: [[]]);
        usort($rows, fn ($a, $b) => strcmp($b['sent_at'] ?? '', $a['sent_at'] ?? ''));

        return new JsonResponse(['data' => $rows]);
    }

    public function show(string $party, string $farm, string $po): JsonResponse
    {
        $link = $this->parties->link();

        return new JsonResponse(['data' => $this->portal->present($this->portal->order($link, $po), $link, true)]);
    }

    public function respond(Request $request, string $party, string $farm, string $po): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:accepted,rejected'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'promised_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'lines' => ['sometimes', 'array', 'max:100'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.confirmed_quantity' => ['required', 'numeric', 'min:0'],
        ]);
        $link = $this->parties->link();
        $order = $this->portal->respond($this->portal->order($link, $po), $data);

        return new JsonResponse(['data' => $this->portal->present($order, $link, true)]);
    }

    public function dispatch(Request $request, string $party, string $farm, string $po): JsonResponse
    {
        $data = $request->validate([
            'dispatched_on' => ['sometimes', 'date', 'before_or_equal:today'],
            'expected_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:dispatched_on'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'media_id' => ['sometimes', 'nullable', 'uuid'],
            'vehicle' => ['sometimes', 'nullable', 'string', 'max:60'],
            'driver' => ['sometimes', 'nullable', 'string', 'max:120'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.order_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $link = $this->parties->link();
        $order = $this->portal->order($link, $po);
        $this->portal->dispatch($order, $data);

        return new JsonResponse(['data' => $this->portal->present($order->refresh(), $link, true)], 201);
    }

    public function submitInvoice(Request $request, string $party, string $farm, string $po): JsonResponse
    {
        $data = $request->validate([
            'invoice_number' => ['required', 'string', 'max:60'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'due_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:invoice_date'],
            'media_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.order_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ]);
        $link = $this->parties->link();
        $order = $this->portal->order($link, $po);
        $this->portal->submitInvoice($order, $data);

        return new JsonResponse(['data' => $this->portal->present($order->refresh(), $link, true)], 201);
    }

    /** Recorded invoices with payment status, and submissions waiting or sent back. */
    public function invoices(): JsonResponse
    {
        $rows = array_merge(...$this->parties->eachFarm('supplier', function (PartyLink $link) {
            $farm = ['id' => $link->farm->id, 'name' => $link->farm->name];
            $recorded = SupplierInvoice::with('order')->where('supplier_id', $link->record_id)->orderByDesc('invoice_date')->limit(200)->get()
                ->map(fn ($i) => ['type' => 'supplier_invoice', 'farm' => $farm, 'order' => ['id' => $i->order->id, 'code' => $i->order->code], 'currency' => $i->order->currency] + $this->portal->presentInvoice($i));
            $waiting = SupplierInvoiceSubmission::with('order')->where('supplier_id', $link->record_id)->where('status', '!=', 'recorded')->orderByDesc('created_at')->limit(200)->get()
                ->map(fn ($s) => ['type' => 'supplier_invoice_submission', 'farm' => $farm, 'order' => ['id' => $s->order->id, 'code' => $s->order->code], 'currency' => $s->order->currency] + $s->toPortal());

            return [...$waiting->all(), ...$recorded->all()];
        }) ?: [[]]);

        return new JsonResponse(['data' => $rows]);
    }

    /** A delivery note or invoice document, stored with the farm it is for. */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('sfmtp.media.max_kb'), 'mimetypes:'.implode(',', config('sfmtp.media.mimes'))],
            'sha256' => ['nullable', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
        ]);
        [$media, $created] = app(MediaStore::class)->store($data['file'], $data['sha256'] ?? null);

        return new JsonResponse(['data' => ['id' => $media->id, 'type' => 'media', 'mime' => $media->mime, 'size_bytes' => $media->size_bytes]], $created ? 201 : 200);
    }
}
