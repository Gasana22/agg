<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FarmService;
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

    public function destroy(): Response
    {
        $this->farms->close($this->context->farm());

        return response()->noContent();
    }
}
