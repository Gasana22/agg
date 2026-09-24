<?php

namespace App\Modules\Livestock\Http\Controllers;

use App\Modules\Livestock\Application\Herd;
use App\Modules\Livestock\Domain\Enums\GroupPurpose;
use App\Modules\Livestock\Domain\Models\AnimalGroup;
use App\Modules\Livestock\Http\Resources\GroupResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GroupController
{
    public function __construct(private readonly Herd $herd) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return GroupResource::collection(AnimalGroup::with('location')->withCount('activeAnimals')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $group = $this->herd->createGroup($request->validate($this->rules(true)));

        return (new GroupResource($group->load('location')->loadCount('activeAnimals')))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, AnimalGroup $group): GroupResource
    {
        OptimisticLock::check($request, $group);
        $data = $request->validate($this->rules(false));

        return new GroupResource($this->herd->updateGroup($group, $data)->load('location')->loadCount('activeAnimals'));
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return array_filter([
            'code' => $creating ? ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'] : null,
            'name' => [$required, 'string', 'max:120'],
            'species_id' => $creating ? ['required', 'uuid', 'exists:global_animal_species,id'] : null,
            'purpose' => ['sometimes', Rule::in(GroupPurpose::values())],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'flock_size' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => $creating ? null : ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }
}
