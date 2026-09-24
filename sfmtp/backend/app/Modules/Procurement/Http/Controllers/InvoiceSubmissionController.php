<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Inventory\Http\Resources\Refs;
use App\Modules\Procurement\Application\InvoiceSubmissions;
use App\Modules\Procurement\Domain\Models\PurchaseOrderLine;
use App\Modules\Procurement\Domain\Models\SupplierInvoiceSubmission;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Farm side: invoices suppliers sent through the portal. */
class InvoiceSubmissionController
{
    private const WITH = ['supplier', 'order', 'submitter', 'reviewer', 'invoice'];

    public function __construct(private readonly InvoiceSubmissions $submissions) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['filter.status' => ['sometimes', 'in:submitted,recorded,rejected'], 'filter.order_id' => ['sometimes', 'uuid']]);
        $f = $data['filter'] ?? [];
        $rows = SupplierInvoiceSubmission::with(self::WITH)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['order_id'] ?? null, fn ($q, $v) => $q->where('order_id', $v))
            ->orderByDesc('created_at')->limit(200)->get();

        return new JsonResponse(['data' => $rows->map(fn ($s) => $this->present($s))->values()]);
    }

    public function record(Request $request, string $farm, string $submission): JsonResponse
    {
        $model = $this->find($submission);
        $data = $request->validate([
            'invoice_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return new JsonResponse(['data' => $this->present($this->submissions->record($model, $data)->load(self::WITH))]);
    }

    public function reject(Request $request, string $farm, string $submission): JsonResponse
    {
        $model = $this->find($submission);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return new JsonResponse(['data' => $this->present($this->submissions->reject($model, $data['reason'])->load(self::WITH))]);
    }

    private function find(string $id): SupplierInvoiceSubmission
    {
        return SupplierInvoiceSubmission::find($id) ?? throw ApiException::notFound();
    }

    /** @return array<string, mixed> */
    private function present(SupplierInvoiceSubmission $s): array
    {
        $items = PurchaseOrderLine::with('item')->whereIn('id', array_column($s->lines ?? [], 'order_line_id'))->get()->keyBy('id');

        return [
            'id' => $s->id,
            'type' => 'supplier_invoice_submission',
            'code' => $s->code,
            'status' => $s->status,
            'supplier' => ['id' => $s->supplier->id, 'code' => $s->supplier->code, 'name' => $s->supplier->name],
            'order' => ['id' => $s->order->id, 'code' => $s->order->code, 'currency' => $s->order->currency],
            'invoice_number' => $s->invoice_number,
            'invoice_date' => $s->invoice_date->toDateString(),
            'due_on' => $s->due_on?->toDateString(),
            'amount' => (float) $s->amount,
            'lines' => array_map(fn ($l) => [
                'order_line_id' => $l['order_line_id'],
                'item' => Refs::item($items->get($l['order_line_id'])?->item),
                'quantity' => (float) $l['quantity'],
                'unit_price' => (float) $l['unit_price'],
            ], $s->lines ?? []),
            'media_id' => $s->media_id,
            'notes' => $s->notes,
            'submitted_by' => Refs::user($s->submitter),
            'submitted_at' => $s->created_at?->toIso8601ZuluString(),
            'reviewed_by' => Refs::user($s->reviewer),
            'reviewed_at' => $s->reviewed_at?->toIso8601ZuluString(),
            'reject_reason' => $s->reject_reason,
            'supplier_invoice' => $s->invoice ? ['id' => $s->invoice->id, 'code' => $s->invoice->code] : null,
        ];
    }
}
