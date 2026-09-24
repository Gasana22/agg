<?php

namespace App\Modules\Livestock\Http\Controllers;

use App\Modules\Livestock\Application\Breedings;
use App\Modules\Livestock\Domain\Enums\BreedingMethod;
use App\Modules\Livestock\Domain\Enums\BreedingStatus;
use App\Modules\Livestock\Domain\Enums\Sex;
use App\Modules\Livestock\Domain\Models\Breeding;
use App\Modules\Livestock\Http\Resources\AnimalResource;
use App\Modules\Livestock\Http\Resources\BreedingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BreedingController
{
    public function __construct(private readonly Breedings $breedings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in([...BreedingStatus::values(), 'open'])],
            'filter.dam_id' => ['sometimes', 'uuid'],
            'filter.due_before' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];

        return BreedingResource::collection(Breeding::with(['dam', 'sire'])
            ->when($f['status'] ?? null, fn ($q, $v) => $v === 'open' ? $q->whereIn('status', ['served', 'pregnant']) : $q->where('status', $v))
            ->when($f['dam_id'] ?? null, fn ($q, $v) => $q->where('dam_id', $v))
            ->when($f['due_before'] ?? null, fn ($q, $v) => $q->where('expected_due_on', '<=', $v))
            ->orderByDesc('served_on')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dam_id' => ['required', 'uuid'],
            'sire_id' => ['nullable', 'uuid'],
            'sire_note' => ['nullable', 'string', 'max:150'],
            'method' => ['required', Rule::in(BreedingMethod::values())],
            'served_on' => ['required', 'date', 'before_or_equal:+1 day'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return (new BreedingResource($this->breedings->serve($data)->load(['dam', 'sire'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, Breeding $breeding): BreedingResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([BreedingStatus::Pregnant->value, BreedingStatus::NotPregnant->value, BreedingStatus::Aborted->value])],
            'on' => ['nullable', 'date', 'before_or_equal:+1 day'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return new BreedingResource($this->breedings->update($breeding, BreedingStatus::from($data['status']), $data['on'] ?? null, $data['note'] ?? null)->load(['dam', 'sire']));
    }

    public function birth(Request $request, string $farm, Breeding $breeding): JsonResponse
    {
        $data = $request->validate([
            'born_on' => ['required', 'date', 'before_or_equal:+1 day'],
            'offspring' => ['required', 'array', 'min:1', 'max:20'],
            'offspring.*.sex' => ['required', Rule::in(Sex::values())],
            'offspring.*.name' => ['nullable', 'string', 'max:80'],
            'offspring.*.tag_number' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $born = $this->breedings->birth($breeding, $data['born_on'], $data['offspring'], $data['note'] ?? null);

        return new JsonResponse([
            'data' => (new BreedingResource($breeding->refresh()->load(['dam', 'sire'])))->resolve($request),
            'meta' => ['offspring' => AnimalResource::collection($born->each->load(['group', 'location', 'dam', 'sire', 'batch']))->resolve($request)],
        ], 201);
    }
}
