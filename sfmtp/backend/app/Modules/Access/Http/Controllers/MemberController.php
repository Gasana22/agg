<?php

namespace App\Modules\Access\Http\Controllers;

use App\Modules\Access\Application\Memberships;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Memberships $memberships,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $members = FarmUser::with('user')
            ->where('farm_id', $this->context->farmId())
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate(min((int) $request->query('per_page', 25), 100));

        $roles = $this->rolesOf(collect($members->items())->pluck('id')->all());

        return JsonResource::collection($members->through(fn (FarmUser $m) => $this->present($m, $roles[$m->id] ?? collect())));
    }

    public function update(Request $request, string $farm, string $member): JsonResponse
    {
        $membership = $this->find($member);   // 404 before 422
        $data = $request->validate([
            'role_ids' => ['sometimes', 'array', 'min:1', 'max:10'],
            'role_ids.*' => ['uuid'],
            'status' => ['sometimes', Rule::in([MembershipStatus::Active->value, MembershipStatus::Suspended->value])],
        ]);

        $updated = $this->memberships->update($membership, $request->user(), $data);

        return new JsonResponse(['data' => $this->present($updated->load('user'), $this->rolesOf([$updated->id])[$updated->id] ?? collect())]);
    }

    public function destroy(Request $request, string $farm, string $member): Response
    {
        $this->memberships->remove($this->find($member), $request->user());

        return response()->noContent();
    }

    private function find(string $id): FarmUser
    {
        return FarmUser::where('farm_id', $this->context->farmId())->whereKey($id)->first() ?? throw ApiException::notFound();
    }

    /** @return array<string, Collection> */
    private function rolesOf(array $memberIds): array
    {
        return DB::table('farm_user_roles')
            ->join('farm_roles', 'farm_roles.id', '=', 'farm_user_roles.farm_role_id')
            ->where('farm_user_roles.farm_id', $this->context->farmId())
            ->whereIn('farm_user_roles.farm_user_id', $memberIds)
            ->orderBy('farm_roles.name')
            ->get(['farm_user_roles.farm_user_id', 'farm_roles.id', 'farm_roles.key', 'farm_roles.name'])
            ->groupBy('farm_user_id')
            ->all();
    }

    private function present(FarmUser $m, $roles): array
    {
        return [
            'id' => $m->id,
            'type' => 'farm_member',
            'user' => ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email],
            'status' => $m->status->value,
            'is_owner' => $m->is_owner,
            'roles' => $roles->map(fn ($r) => ['id' => $r->id, 'key' => $r->key, 'name' => $r->name])->values(),
            'joined_at' => $m->joined_at?->toIso8601ZuluString(),
        ];
    }
}
