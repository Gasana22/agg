<?php

namespace App\Modules\Livestock\Http\Controllers;

use App\Modules\Access\Application\ScopedAccess;
use App\Modules\Livestock\Application\Herd;
use App\Modules\Livestock\Application\Timeline;
use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Enums\Origin;
use App\Modules\Livestock\Domain\Enums\Sex;
use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Http\Resources\AnimalResource;
use App\Support\Http\ApiException;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnimalController
{
    private const WITH = ['group', 'location', 'dam', 'sire', 'batch'];

    public function __construct(
        private readonly Herd $herd,
        private readonly ScopedAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in([...AnimalStatus::values(), 'all'])],
            'filter.species_id' => ['sometimes', 'uuid'],
            'filter.group_id' => ['sometimes', 'uuid'],
            'filter.sex' => ['sometimes', Rule::in(Sex::values())],
            'filter.withdrawal' => ['sometimes', 'boolean'],
            'filter.pregnant' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];
        $status = $f['status'] ?? 'active';
        $today = now()->toDateString();

        $animals = $this->query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($f['species_id'] ?? null, fn ($q, $v) => $q->where('species_id', $v))
            ->when($f['group_id'] ?? null, fn ($q, $v) => $q->where('group_id', $v))
            ->when($f['sex'] ?? null, fn ($q, $v) => $q->where('sex', $v))
            ->when(filter_var($f['withdrawal'] ?? false, FILTER_VALIDATE_BOOL), fn ($q) => $q->where(fn ($w) => $w->where('meat_withdrawal_until', '>=', $today)->orWhere('milk_withdrawal_until', '>=', $today)))
            ->when(filter_var($f['pregnant'] ?? false, FILTER_VALIDATE_BOOL), fn ($q) => $q->whereIn('id', DB::table('animal_breedings')->where('status', 'pregnant')->select('dam_id')))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('animal_code', 'like', '%'.strtoupper($v).'%')
                ->orWhere('tag_number', 'like', "%{$v}%")
                ->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($v).'%'])))
            ->orderBy('animal_code')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50));

        return AnimalResource::collection($animals);
    }

    public function show(string $farm, Animal $animal): AnimalResource
    {
        return new AnimalResource($this->visible($animal->id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::rules(true));
        $animal = $this->herd->register($data);

        return (new AnimalResource($this->visible($animal->id)))->response()->setStatusCode(201)
            ->header('Location', url("/api/v1/farms/{$animal->farm_id}/animals/{$animal->id}"));
    }

    public function update(Request $request, string $farm, Animal $animal): AnimalResource
    {
        OptimisticLock::check($request, $animal);
        $data = $request->validate(self::rules(false));

        return new AnimalResource($this->visible($this->herd->update($animal, $data)->id));
    }

    public function exit(Request $request, string $farm, Animal $animal): AnimalResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([AnimalStatus::Dead->value, AnimalStatus::Culled->value, AnimalStatus::Transferred->value])],
            'date' => ['required', 'date', 'before_or_equal:+1 day'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return new AnimalResource($this->visible($this->herd->exit($animal, AnimalStatus::from($data['status']), $data['date'], $data['reason'])->id));
    }

    public function timeline(Request $request, string $farm, Animal $animal, Timeline $timeline): JsonResponse
    {
        $this->visible($animal->id);

        return new JsonResponse(['data' => $timeline->for($animal, $request)]);
    }

    private function query()
    {
        return $this->access->scoped(Animal::with(self::WITH), 'livestock.animals.view', 'created_by')
            ->addSelect(['pregnancy_due_on' => DB::table('animal_breedings')->select('expected_due_on')
                ->whereColumn('animal_breedings.dam_id', 'animals.id')->where('status', 'pregnant')->limit(1)]);
    }

    private function visible(string $id): Animal
    {
        return $this->query()->whereKey($id)->first() ?? throw ApiException::notFound();
    }

    /** Also used by offline sync for edits. */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return array_filter([
            'species_id' => $creating ? ['required', 'uuid'] : null,
            'breed_id' => ['sometimes', 'nullable', 'uuid'],
            'breed_note' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sex' => $creating ? ['required', Rule::in(Sex::values())] : null,
            'name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'tag_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'rfid' => ['sometimes', 'nullable', 'string', 'max:40'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'birth_date_estimated' => ['sometimes', 'boolean'],
            'origin' => $creating ? ['required', Rule::in(Origin::values())] : null,
            'acquired_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:+1 day'],
            'dam_id' => $creating ? ['nullable', 'uuid'] : null,
            'sire_id' => $creating ? ['nullable', 'uuid'] : null,
            'parentage_note' => ['sometimes', 'nullable', 'string', 'max:200'],
            'group_id' => ['sometimes', 'nullable', 'uuid'],
            'location_id' => $creating ? ['nullable', 'uuid'] : null,
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }
}
