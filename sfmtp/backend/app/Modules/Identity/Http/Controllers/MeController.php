<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\TokenService;
use App\Modules\Identity\Contracts\MfaRequirement;
use App\Modules\Identity\Domain\Events\DeviceRevoked;
use App\Modules\Identity\Http\Resources\DeviceResource;
use App\Modules\Identity\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MeController
{
    public function show(Request $request, MfaRequirement $requirement): UserResource
    {
        $user = $request->user();

        return (new UserResource($user))->additional(['meta' => [
            'mfa_required' => $requirement->requiresMfa($user),
        ]]);
    }

    public function devices(Request $request): AnonymousResourceCollection
    {
        return DeviceResource::collection(
            $request->user()->devices()->whereNull('revoked_at')->latest('last_seen_at')->get()
        );
    }

    public function revokeDevice(Request $request, string $device, TokenService $tokens): Response
    {
        $model = $request->user()->devices()->whereKey($device)->whereNull('revoked_at')->firstOrFail();
        $model->forceFill(['revoked_at' => now()])->save();
        $tokens->revokeDevice($model->id);
        DeviceRevoked::dispatch($request->user(), $model->id);

        return response()->noContent();
    }
}
