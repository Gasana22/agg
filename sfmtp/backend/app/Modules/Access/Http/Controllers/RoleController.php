<?php

namespace App\Modules\Access\Http\Controllers;

use App\Modules\Access\Application\PermissionRegistry;
use App\Modules\Access\Application\RoleService;
use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Access\Http\Resources\RoleResource;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class RoleController
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): AnonymousResourceCollection
    {
        $roles = FarmRole::with('permissions')->orderByDesc('is_system')->orderBy('name')->get();
        $counts = DB::table('farm_user_roles')->whereIn('farm_role_id', $roles->pluck('id'))
            ->groupBy('farm_role_id')->select('farm_role_id', DB::raw('COUNT(*) AS n'))->pluck('n', 'farm_role_id');
        $roles->each(fn (FarmRole $r) => $r->setAttribute('member_count', (int) ($counts[$r->id] ?? 0)));

        return RoleResource::collection($roles);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'copy_from_role_id' => ['nullable', 'uuid'],
            'grants' => ['sometimes', 'array'],
            'grants.*' => ['string'],
        ]);

        $copyFrom = null;
        if (! empty($data['copy_from_role_id'])) {
            $copyFrom = FarmRole::with('permissions')->find($data['copy_from_role_id'])
                ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
                    'copy_from_role_id' => ['The selected role does not exist in this farm.'],
                ]);
        }

        $role = $this->roles->createRole([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'copy_from' => $copyFrom,
            'grants' => $data['grants'] ?? null,
        ]);

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, FarmRole $role): RoleResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return new RoleResource($this->roles->updateRole($role, $data));
    }

    public function destroy(string $farm, FarmRole $role): Response
    {
        $this->roles->deleteRole($role);

        return response()->noContent();
    }

    public function updatePermissions(Request $request, string $farm, FarmRole $role): RoleResource
    {
        $data = $request->validate([
            'grants' => ['present', 'array'],
            'grants.*' => ['string'],
        ]);

        return new RoleResource($this->roles->syncPermissions($role, $data['grants']));
    }

    /** The permission registry, for the role editor. */
    public function permissions(): JsonResponse
    {
        $data = [];
        foreach (PermissionRegistry::all() as $key => $def) {
            $data[] = ['key' => $key] + $def;
        }

        return new JsonResponse(['data' => $data]);
    }
}
