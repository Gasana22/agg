<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\SalesOrders;
use App\Modules\Sales\Domain\Models\Product;
use App\Support\Http\ApiException;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController
{
    public function __construct(private readonly SalesOrders $orders) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['filter.published' => ['sometimes', 'boolean'], 'filter.active' => ['sometimes', 'boolean'], 'q' => ['sometimes', 'string', 'max:100']]);
        $f = $data['filter'] ?? [];
        $rows = Product::with(['item', 'incomeAccount'])
            ->when(isset($f['published']), fn ($q) => $q->where('is_published', filter_var($f['published'], FILTER_VALIDATE_BOOLEAN)))
            ->when(isset($f['active']), fn ($q) => $q->where('is_active', filter_var($f['active'], FILTER_VALIDATE_BOOLEAN)))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($v).'%']))
            ->orderBy('name')->limit(500)->get();

        return new JsonResponse(['data' => $rows->map(fn ($p) => self::present($p))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $product = $this->orders->createProduct($request->validate($this->rules(true)));

        return new JsonResponse(['data' => self::present($product->load(['item', 'incomeAccount']))], 201);
    }

    public function update(Request $request, string $farm, string $product): JsonResponse
    {
        $model = Product::find($product) ?? throw ApiException::notFound();
        OptimisticLock::check($request, $model);
        $model = $this->orders->updateProduct($model, $request->validate($this->rules(false)));

        return new JsonResponse(['data' => self::present($model->load(['item', 'incomeAccount']))]);
    }

    /** @return array<string, mixed> */
    public static function present(Product $p): array
    {
        return [
            'id' => $p->id,
            'type' => 'product',
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
            'category' => $p->category,
            'unit' => $p->unit,
            'list_price' => (float) $p->list_price,
            'currency' => $p->currency,
            'min_order_quantity' => $p->min_order_quantity === null ? null : (float) $p->min_order_quantity,
            'availability_note' => $p->availability_note,
            'inventory_item' => $p->item ? ['id' => $p->item->id, 'name' => $p->item->name] : null,
            'income_account' => $p->incomeAccount ? ['id' => $p->incomeAccount->id, 'code' => $p->incomeAccount->code, 'name' => $p->incomeAccount->name] : null,
            'media_id' => $p->media_id,
            'is_published' => $p->is_published,
            'is_active' => $p->is_active,
            'version' => $p->version,
        ];
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'min:2', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'unit' => [$req, 'string', 'max:20'],
            'list_price' => [$req, 'numeric', 'min:0', 'max:9999999999'],
            'min_order_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'availability_note' => ['sometimes', 'nullable', 'string', 'max:200'],
            'inventory_item_id' => ['sometimes', 'nullable', 'uuid'],
            'income_account_id' => ['sometimes', 'nullable', 'uuid'],
            'media_id' => ['sometimes', 'nullable', 'uuid'],
            'is_published' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
