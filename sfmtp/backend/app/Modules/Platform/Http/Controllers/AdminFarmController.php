<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Platform\Application\FarmAdministration;
use App\Modules\Platform\Domain\Models\FarmStatusChange;
use App\Modules\Platform\Http\Resources\AdminFarmResource;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class AdminFarmController
{
    public function __construct(private readonly FarmAdministration $farms) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(FarmStatus::values())],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        $page = Farm::with('organization')
            ->withCount(['members as member_count' => fn ($q) => $q->where('status', 'active')])
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($v).'%'])
                ->orWhere('code', strtoupper($v))))
            ->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        $this->decorate(collect($page->items()));

        return AdminFarmResource::collection($page);
    }

    public function show(string $farm): AdminFarmResource
    {
        return $this->present(Farm::findOrFail($farm));
    }

    private function present(Farm $farm): AdminFarmResource
    {
        $farm->load('organization')->loadCount(['members as member_count' => fn ($q) => $q->where('status', 'active')]);
        $this->decorate(collect([$farm]));
        $farm->setAttribute('history', FarmStatusChange::where('farm_id', $farm->id)->orderByDesc('created_at')->get()->map(fn ($h) => [
            'from_status' => $h->from_status,
            'to_status' => $h->to_status,
            'reason_code' => $h->reason_code,
            'note' => $h->note,
            'changed_by' => $h->changed_by,
            'at' => $h->created_at->toIso8601ZuluString(),
        ])->all());

        return new AdminFarmResource($farm);
    }

    public function approve(Request $request, string $farm): AdminFarmResource
    {
        $farm = Farm::findOrFail($farm);
        $this->farms->approve($farm, $request->user());

        return $this->present($farm->refresh());
    }

    public function suspend(Request $request, string $farm): AdminFarmResource
    {
        $farm = Farm::findOrFail($farm);
        $data = $request->validate([
            'reason_code' => ['required', Rule::in(FarmAdministration::SUSPENSION_REASONS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->farms->suspend($farm, $request->user(), $data['reason_code'], $data['note'] ?? null);

        return $this->present($farm->refresh());
    }

    public function unsuspend(Request $request, string $farm): AdminFarmResource
    {
        $farm = Farm::findOrFail($farm);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->farms->unsuspend($farm, $request->user(), $data['note'] ?? null);

        return $this->present($farm->refresh());
    }

    public function resetOwnerPassword(string $farm): JsonResponse
    {
        $farm = Farm::findOrFail($farm);
        $this->farms->sendOwnerPasswordReset($farm);

        return new JsonResponse(['data' => ['status' => 'reset_link_sent']], 202);
    }

    /** Attach owner and subscription summaries without N+1 queries. */
    private function decorate(Collection $farms): void
    {
        $owners = FarmUser::with('user')->whereIn('farm_id', $farms->pluck('id'))->where('is_owner', true)->get()->keyBy('farm_id');
        $subscriptions = Subscription::with('plan')->whereIn('organization_id', $farms->pluck('organization_id'))->get()->keyBy('organization_id');

        foreach ($farms as $farm) {
            $farm->setAttribute('owner_user', $owners[$farm->id]->user ?? null);
            $s = $subscriptions[$farm->organization_id] ?? null;
            $farm->setAttribute('subscription_summary', $s ? [
                'id' => $s->id,
                'status' => $s->status->value,
                'plan' => $s->plan->code,
                'current_period_end' => $s->current_period_end->toDateString(),
            ] : null);
        }
    }
}
