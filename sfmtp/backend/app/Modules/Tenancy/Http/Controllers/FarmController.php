<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FarmService;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Http\Requests\StoreFarmRequest;
use App\Modules\Tenancy\Http\Resources\FarmResource;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FarmController
{
    public function __construct(
        private readonly FarmService $farms,
        private readonly TenantContext $context,
    ) {}

    /** Farms the caller is an active member of. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $farms = Farm::query()
            ->whereIn('id', fn ($q) => $q->select('farm_id')->from('farm_users')
                ->where('user_id', $request->user()->id)
                ->where('status', MembershipStatus::Active->value))
            ->where('status', '!=', FarmStatus::Closed->value)
            ->orderBy('name')
            ->orderBy('id')
            ->cursorPaginate(min((int) $request->query('per_page', 25), 100));

        return FarmResource::collection($farms);
    }

    public function store(StoreFarmRequest $request): JsonResponse
    {
        $farm = $this->farms->create($request->user(), $request->validated());

        return (new FarmResource($farm))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/farms/{$farm->id}"));
    }

    public function show(): FarmResource
    {
        return new FarmResource($this->context->farm());
    }

    public function update(StoreFarmRequest $request): FarmResource
    {
        return new FarmResource($this->farms->update($this->context->farm(), $request->validated()));
    }

    public function settings(FarmSettings $settings): JsonResponse
    {
        return new JsonResponse(['data' => $settings->get($this->context->farm())]);
    }

    public function updateSettings(Request $request, FarmSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'require_mfa_for_all' => ['sometimes', 'boolean'],
            'approval_thresholds' => ['sometimes', 'array:expense,purchase_order,stock_adjustment_pct'],
            'approval_thresholds.expense' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'approval_thresholds.purchase_order' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'approval_thresholds.stock_adjustment_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'units' => ['sometimes', 'in:metric,imperial'],
        ]);

        foreach ($data['approval_thresholds'] ?? [] as $key => $value) {
            $data['approval_thresholds'][$key] = $value === null ? null : (float) $value;
        }
        foreach (['require_mfa_for_all', 'allow_negative_stock'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = (bool) $data[$key];
            }
        }

        return new JsonResponse(['data' => $settings->update($this->context->farm(), $data)]);
    }

    public function destroy(): Response
    {
        $this->farms->close($this->context->farm());

        return response()->noContent();
    }
}
