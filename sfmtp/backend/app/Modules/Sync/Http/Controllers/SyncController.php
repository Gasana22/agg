<?php

namespace App\Modules\Sync\Http\Controllers;

use App\Modules\Sync\Application\FieldMerge;
use App\Modules\Sync\Application\SyncEntities;
use App\Modules\Sync\Application\SyncPull;
use App\Modules\Sync\Application\SyncPush;
use App\Modules\Sync\Domain\Models\SyncConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SyncController
{
    public function __construct(private readonly SyncPush $pushes, private readonly SyncPull $pulls) {}

    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['sometimes', 'nullable', 'uuid'],
            'mutations' => ['present', 'array', 'max:'.config('sfmtp.sync.max_mutations')],
            'mutations.*.mutation_id' => ['required', 'uuid', 'distinct'],
            'mutations.*.entity' => ['required', 'string', 'max:40'],
            'mutations.*.op' => ['required', 'string', 'max:20'],
            'mutations.*.id' => ['nullable', 'uuid'],
            'mutations.*.base_version' => ['nullable', 'integer'],
            'mutations.*.occurred_at' => ['nullable', 'date'],
            'mutations.*.data' => ['present', 'array'],
        ]);
        // The device on the access token wins over one in the body.
        $deviceId = $request->attributes->get('device_id') ?? ($data['device_id'] ?? null);

        return response()->json(['data' => ['results' => $this->pushes->push($data['mutations'], $deviceId, $request)]]);
    }

    public function pull(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'entities' => ['sometimes', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.config('sfmtp.sync.pull_limit')],
        ]);
        $entities = isset($data['entities']) ? array_values(array_intersect(explode(',', $data['entities']), SyncEntities::ENTITIES)) : SyncEntities::ENTITIES;
        $request->validate(['entities' => [Rule::requiredIf($entities === [])]], ['entities.required' => 'Ask for at least one of: '.implode(', ', SyncEntities::ENTITIES).'.']);

        return response()->json(['data' => $this->pulls->pull(
            isset($data['cursor']) ? (int) $data['cursor'] : null,
            $entities,
            (int) ($data['limit'] ?? config('sfmtp.sync.pull_limit')),
            $request,
        )]);
    }

    /** The member's open conflicts (the phone also gets them through pull). */
    public function conflicts(): JsonResponse
    {
        return response()->json(['data' => SyncConflict::where('user_id', Auth::id())->where('status', 'open')->orderByDesc('created_at')->get()->map->toArrayForMember()->all()]);
    }

    public function resolve(Request $request, string $farm, SyncConflict $syncConflict, FieldMerge $merge): JsonResponse
    {
        $data = $request->validate(['choices' => ['required', 'array']]);

        return response()->json(['data' => $merge->resolve($syncConflict, $data['choices'])->toArrayForMember()]);
    }
}
