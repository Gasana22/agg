<?php

namespace App\Modules\Access\Http\Controllers;

use App\Modules\Access\Application\PermissionRegistry;
use App\Modules\Access\Application\RoleService;
use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Access\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController
{
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(FarmRole::with('permissions')->orderBy('name')->get());
    }

    public function updatePermissions(Request $request, string $farm, FarmRole $role, RoleService $roles): RoleResource
    {
        $data = $request->validate([
            'grants' => ['present', 'array'],
            'grants.*' => ['string'],
        ]);

        return new RoleResource($roles->syncPermissions($role, $data['grants']));
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
