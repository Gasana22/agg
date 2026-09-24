<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Finance\Application\CostCenters;
use App\Modules\Sales\Application\Invoicing;
use App\Modules\Sales\Domain\Models\CustomerInvoice;
use App\Modules\Sales\Http\Resources\CustomerInvoiceResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CustomerInvoiceController
{
    private const WITH = ['customer', 'lines.account', 'creator', 'issuer'];

    public function __construct(private readonly Invoicing $invoicing) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:100'],
            'filter.customer_id' => ['sometimes', 'uuid'],
            'filter.overdue' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];

        return CustomerInvoiceResource::collection(CustomerInvoice::with(['customer', 'creator', 'issuer'])
            ->when($f['status'] ?? null, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when(filter_var($f['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->where('status', 'issued')->whereDate('due_on', '<', now()->toDateString()))
            ->orderByDesc('invoice_date')->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function show(string $farm, CustomerInvoice $customerInvoice): CustomerInvoiceResource
    {
        return new CustomerInvoiceResource($customerInvoice->load(self::WITH));
    }

    public function store(Request $request): JsonResponse
    {
        $invoice = $this->invoicing->create($request->validate($this->rules(true)));

        return (new CustomerInvoiceResource($invoice->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, CustomerInvoice $customerInvoice): CustomerInvoiceResource
    {
        OptimisticLock::check($request, $customerInvoice);

        return new CustomerInvoiceResource($this->invoicing->update($customerInvoice, $request->validate($this->rules(false)))->load(self::WITH));
    }

    public function issue(string $farm, CustomerInvoice $customerInvoice): CustomerInvoiceResource
    {
        return new CustomerInvoiceResource($this->invoicing->issue($customerInvoice)->load(self::WITH));
    }

    public function void(Request $request, string $farm, CustomerInvoice $customerInvoice): CustomerInvoiceResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new CustomerInvoiceResource($this->invoicing->void($customerInvoice, $data['reason'])->load(self::WITH));
    }

    /** Completed livestock sales still to invoice. */
    public function billableSales(): JsonResponse
    {
        return response()->json(['data' => $this->invoicing->billableSales()->map(fn ($s) => [
            'id' => $s->id,
            'code' => $s->code,
            'sold_on' => $s->sold_on?->toDateString(),
            'buyer' => $s->buyer,
            'sale_price' => $s->sale_price === null ? null : (float) $s->sale_price,
            'animal' => ['id' => $s->animal->id, 'animal_code' => $s->animal->animal_code, 'tag_number' => $s->animal->tag_number, 'name' => $s->animal->name,
                'group' => $s->animal->group ? ['id' => $s->animal->group->id, 'code' => $s->animal->group->code, 'name' => $s->animal->group->name] : null],
        ])->values()]);
    }

    private function rules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return [
            'customer_id' => [$r, 'uuid'],
            'invoice_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => [$r, 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.cost_center_type' => ['sometimes', 'nullable', Rule::in([...CostCenters::types(), 'general'])],
            'lines.*.cost_center_id' => ['sometimes', 'nullable', 'uuid'],
            'lines.*.animal_sale_id' => ['sometimes', 'nullable', 'uuid'],
            'version' => ['sometimes', 'integer'],
        ];
    }
}
