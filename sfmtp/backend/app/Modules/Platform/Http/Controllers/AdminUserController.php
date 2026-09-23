<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Application\TokenService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\Enums\UserType;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformPermissions;
use App\Modules\Platform\Application\PlatformRoles;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Account administration: block/unblock any account, and manage platform
 * staff. Never touches what those users do inside farms.
 */
class AdminUserController
{
    public function __construct(
        private readonly PlatformPermissions $platform,
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.user_type' => ['sometimes', Rule::in(UserType::values())],
            'filter.status' => ['sometimes', Rule::in(UserStatus::values())],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        $page = User::query()
            ->when($filter['user_type'] ?? null, fn ($q, $v) => $q->where('user_type', $v))
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($v).'%'])
                ->orWhere('email', 'like', '%'.mb_strtolower($v).'%')))
            ->orderBy('name')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        $farmCounts = DB::table('farm_users')->whereIn('user_id', collect($page->items())->pluck('id'))
            ->where('status', 'active')->select('user_id', DB::raw('count(*) as c'))->groupBy('user_id')->pluck('c', 'user_id');

        return JsonResource::collection($page->through(fn (User $u) => $this->present($u, (int) ($farmCounts[$u->id] ?? 0))));
    }

    /** Invite a platform staff member: they set a password through the reset link. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'platform_roles' => ['required', 'array', 'min:1'],
            'platform_roles.*' => [Rule::in(array_keys(PlatformRoles::roles()))],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'user_type' => UserType::PlatformAdmin,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Str::password(40),     // unusable until reset
                'status' => UserStatus::Active,
            ]);
            foreach (array_unique($data['platform_roles']) as $role) {
                $this->platform->assign($user, $role);
            }

            return $user;
        });

        Password::sendResetLink(['email' => $user->email]);
        $this->audit->record('admin.staff_invited', $user, null, ['email' => $user->email, 'roles' => $data['platform_roles']], ['farm_id' => null]);

        return new JsonResponse(['data' => $this->present($user, 0)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(UserStatus::values())],
            'platform_roles' => ['sometimes', 'array'],
            'platform_roles.*' => [Rule::in(array_keys(PlatformRoles::roles()))],
        ]);

        if ($user->id === $request->user()->id) {
            throw ApiException::forbidden('cannot_modify_self', 'You cannot change your own status or roles.');
        }
        if (isset($data['platform_roles']) && ! $user->isPlatformAdmin()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['platform_roles' => ['Only platform staff hold platform roles.']]);
        }

        $before = ['status' => $user->status->value, 'platform_roles' => $this->platform->rolesOf($user)];

        DB::transaction(function () use ($user, $data) {
            if (isset($data['status'])) {
                $user->forceFill(['status' => $data['status']])->save();
                if ($data['status'] === UserStatus::Disabled->value) {
                    $this->tokens->revokeAllForUser($user->id);   // signed out everywhere, immediately
                }
            }
            if (isset($data['platform_roles'])) {
                DB::table('platform_user_roles')->where('user_id', $user->id)->delete();
                foreach (array_unique($data['platform_roles']) as $role) {
                    $this->platform->assign($user, $role);
                }
            }
        });

        $after = ['status' => $user->refresh()->status->value, 'platform_roles' => (new PlatformPermissions)->rolesOf($user)];
        $this->audit->record('admin.user_updated', $user, $before, $after, ['farm_id' => null]);

        return new JsonResponse(['data' => $this->present($user, 0)]);
    }

    private function present(User $user, int $farmCount): array
    {
        return [
            'id' => $user->id,
            'type' => 'admin_user',
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => $user->user_type->value,
            'status' => $user->status->value,
            'mfa_enabled' => $user->hasMfa(),
            'platform_roles' => $user->isPlatformAdmin() ? (new PlatformPermissions)->rolesOf($user) : [],
            'farm_count' => $farmCount,
            'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
            'created_at' => $user->created_at?->toIso8601ZuluString(),
        ];
    }
}
