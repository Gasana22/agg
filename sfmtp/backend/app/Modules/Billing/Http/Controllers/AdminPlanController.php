<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Http\Resources\PlanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Configurable plans — never hard-coded (requirements §35). No deletes: deactivate. */
class AdminPlanController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::orderBy('sort_order')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $plan = Plan::create($this->validated($request, null));
        $this->audit->record('admin.plan_created', $plan, null, $plan->only(['code', 'price', 'max_farms', 'max_users']), ['farm_id' => null]);

        return (new PlanResource($plan->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Plan $plan): PlanResource
    {
        $before = $plan->only(['name', 'price', 'max_farms', 'max_users', 'max_storage_mb', 'is_active', 'is_public']);
        $plan->fill($this->validated($request, $plan))->save();
        $this->audit->record('admin.plan_updated', $plan, $before, $plan->only(array_keys($before)), ['farm_id' => null]);

        return new PlanResource($plan);
    }

    private function validated(Request $request, ?Plan $plan): array
    {
        $req = $plan ? 'sometimes' : 'required';
        $data = $request->validate([
            'code' => [$plan ? 'prohibited' : 'required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('subscription_plans', 'code')],
            'name' => [$req, 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => [$req, 'numeric', 'min:0', 'max:999999999999'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'billing_period' => [$req, Rule::in(['monthly', 'yearly'])],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'max_farms' => ['nullable', 'integer', 'min:1'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_storage_mb' => ['nullable', 'integer', 'min:1'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        if (! $plan) {
            $data['features'] ??= [];
        }

        return $data;
    }
}
