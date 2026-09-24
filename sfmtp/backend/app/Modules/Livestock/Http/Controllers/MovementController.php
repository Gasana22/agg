<?php

namespace App\Modules\Livestock\Http\Controllers;

use App\Modules\Access\Application\ScopedAccess;
use App\Modules\Livestock\Application\AnimalRecords;
use App\Modules\Livestock\Domain\Models\Movement;
use App\Modules\Livestock\Http\Resources\RecordResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MovementController
{
    public function __construct(
        private readonly AnimalRecords $records,
        private readonly ScopedAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.animal_id' => ['sometimes', 'uuid'],
            'filter.group_id' => ['sometimes', 'uuid'],
            'filter.location_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];

        return RecordResource::collection($this->access->scoped(Movement::with(['animal', 'group', 'recorder']), 'livestock.animals.view')
            ->when($f['animal_id'] ?? null, fn ($q, $v) => $q->where('animal_id', $v))
            ->when($f['group_id'] ?? null, fn ($q, $v) => $q->where('group_id', $v))
            ->when($f['location_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('to_location_id', $v)->orWhere('from_location_id', $v)))
            ->orderByDesc('moved_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'animal_ids' => ['required_without:group_id', 'array', 'max:500'],
            'animal_ids.*' => ['uuid'],
            'group_id' => ['required_without:animal_ids', 'nullable', 'uuid'],
            'to_location_id' => ['required', 'uuid'],
            'moved_at' => ['sometimes', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'reason' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $moves = $this->records->move($data);

        return RecordResource::collection($moves->each->load(['animal', 'group', 'recorder']))->response()->setStatusCode(201);
    }
}
