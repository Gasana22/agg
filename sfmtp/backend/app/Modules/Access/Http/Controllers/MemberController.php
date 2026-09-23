<?php

namespace App\Modules\Access\Http\Controllers;

use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class MemberController
{
    public function index(Request $request, TenantContext $context): AnonymousResourceCollection
    {
        $members = FarmUser::with('user')
            ->where('farm_id', $context->farmId())
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate(min((int) $request->query('per_page', 25), 100));

        $roles = DB::table('farm_user_roles')
            ->join('farm_roles', 'farm_roles.id', '=', 'farm_user_roles.farm_role_id')
            ->where('farm_user_roles.farm_id', $context->farmId())
            ->whereIn('farm_user_roles.farm_user_id', collect($members->items())->pluck('id'))
            ->get(['farm_user_roles.farm_user_id', 'farm_roles.key', 'farm_roles.name'])
            ->groupBy('farm_user_id');

        return JsonResource::collection($members->through(fn (FarmUser $m) => [
            'id' => $m->id,
            'type' => 'farm_member',
            'user' => ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email],
            'status' => $m->status->value,
            'is_owner' => $m->is_owner,
            'roles' => ($roles[$m->id] ?? collect())->map(fn ($r) => ['key' => $r->key, 'name' => $r->name])->values(),
            'joined_at' => $m->joined_at?->toIso8601ZuluString(),
        ]));
    }
}
