<?php

namespace App\Modules\Livestock\Http\Controllers;

use App\Modules\Access\Application\ScopedAccess;
use App\Modules\Livestock\Application\AnimalRecords;
use App\Modules\Livestock\Domain\Enums\HealthKind;
use App\Modules\Livestock\Domain\Enums\ProductKind;
use App\Modules\Livestock\Domain\Enums\WeightMethod;
use App\Modules\Livestock\Domain\Models\AnimalRecord;
use App\Modules\Livestock\Http\Resources\RecordResource;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Health, feeding, weight and production records. Each route passes the
 * record type as a route default (`type`).
 */
class RecordController
{
    private const DATE_COLUMN = ['health' => 'given_on', 'feeding' => 'fed_on', 'weight' => 'weighed_on', 'production' => 'produced_on'];

    public function __construct(
        private readonly AnimalRecords $records,
        private readonly ScopedAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $type = $this->type($request);
        $data = $request->validate([
            'filter.animal_id' => ['sometimes', 'uuid'],
            'filter.group_id' => ['sometimes', 'uuid'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date'],
            'filter.kind' => ['sometimes', 'string', 'max:20'],
            'filter.product' => ['sometimes', Rule::in(ProductKind::values())],
            'filter.due_before' => ['sometimes', 'date'],
            'include_voided' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];
        $class = AnimalRecords::TYPES[$type];
        $date = self::DATE_COLUMN[$type];

        $records = $this->access->scoped($class::with(array_merge(['animal', 'group', 'recorder', 'void'], $type === 'production' ? ['lot'] : [])), 'livestock.animals.view')
            ->when(! $request->boolean('include_voided'), fn ($q) => $q->notVoided())
            ->when($f['animal_id'] ?? null, fn ($q, $v) => $q->where('animal_id', $v))
            ->when($f['group_id'] ?? null, fn ($q, $v) => $q->where('group_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where($date, '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where($date, '<=', $v))
            ->when($type === 'health' && ($f['kind'] ?? null), fn ($q) => $q->where('kind', $f['kind']))
            ->when($type === 'health' && ($f['due_before'] ?? null), fn ($q) => $q->whereNotNull('next_due_on')->where('next_due_on', '<=', $f['due_before']))
            ->when($type === 'production' && ($f['product'] ?? null), fn ($q) => $q->where('product', $f['product']))
            ->orderByDesc($date)->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50));

        return RecordResource::collection($records);
    }

    public function store(Request $request): JsonResponse
    {
        $type = $this->type($request);
        $data = $request->validate(self::rulesFor($type));

        $record = $this->records->{$type}($data);

        return (new RecordResource($record->load(['animal', 'group', 'recorder', 'void'])))->response()->setStatusCode(201);
    }

    public function void(Request $request): RecordResource
    {
        $type = $this->type($request);
        $record = $request->route($request->route()->defaults['param']);
        if (! $record instanceof AnimalRecord) {
            throw ApiException::notFound();
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->records->void($record, $data['reason']);

        return new RecordResource($record->fresh()->load(['animal', 'group', 'recorder', 'void']));
    }

    /** Rules for a record type (health, feeding, weight, production); also used by offline sync. */
    public static function rulesFor(string $type): array
    {
        $subject = [
            'animal_id' => ['nullable', 'uuid', $type === 'weight' ? 'required' : 'required_without:group_id'],
            'group_id' => $type === 'weight' ? ['prohibited'] : ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        return $subject + match ($type) {
            'health' => [
                'kind' => ['required', Rule::in(HealthKind::values())],
                'given_on' => ['required', 'date', 'before_or_equal:+1 day'],
                'diagnosis' => ['nullable', 'string', 'max:200'],
                'product_name' => ['nullable', 'string', 'max:150', 'required_if:kind,vaccination,deworming'],
                'dose' => ['nullable', 'numeric', 'gt:0'],
                'dose_unit' => ['nullable', 'string', 'exists:units,code', 'required_with:dose'],
                'input_batch_id' => ['nullable', 'uuid'],
                'meat_withdrawal_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
                'milk_withdrawal_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
                'next_due_on' => ['nullable', 'date', 'after:given_on'],
                'given_by' => ['nullable', 'string', 'max:120'],
            ],
            'feeding' => [
                'fed_on' => ['required', 'date', 'before_or_equal:+1 day'],
                'feed_name' => ['required', 'string', 'max:150'],
                'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
                'unit' => ['required', 'string', 'exists:units,code'],
                'input_batch_id' => ['nullable', 'uuid'],
            ],
            'weight' => [
                'weighed_on' => ['required', 'date', 'before_or_equal:+1 day'],
                'weight_kg' => ['required', 'numeric', 'gt:0', 'max:5000'],
                'method' => ['sometimes', Rule::in(WeightMethod::values())],
            ],
            'production' => [
                'product' => ['required', Rule::in(ProductKind::values())],
                'produced_on' => ['required', 'date', 'before_or_equal:+1 day'],
                'session' => ['nullable', Rule::in(['am', 'pm', 'day'])],
                'quantity' => ['required', 'numeric', 'min:0', 'max:99999999'],
                'unit' => ['required', 'string', 'exists:units,code'],
                'discarded' => ['sometimes', 'boolean'],
            ],
        };
    }

    private function type(Request $request): string
    {
        return $request->route()->defaults['type'];
    }
}
