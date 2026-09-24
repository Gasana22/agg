<?php

namespace App\Modules\Livestock\Application;

use App\Modules\Access\Application\ScopedAccess;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Catalog\Application\Units;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Enums\HealthKind;
use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\AnimalGroup;
use App\Modules\Livestock\Domain\Models\AnimalRecord;
use App\Modules\Livestock\Domain\Models\AnimalRecordVoid;
use App\Modules\Livestock\Domain\Models\Feeding;
use App\Modules\Livestock\Domain\Models\HealthRecord;
use App\Modules\Livestock\Domain\Models\Movement;
use App\Modules\Livestock\Domain\Models\ProductionRecord;
use App\Modules\Livestock\Domain\Models\Weight;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceBatchLink;
use App\Support\Http\ApiException;
use App\Support\Time\EventTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Health, feeding, weight, production and movement records, about one
 * animal or a whole group. Records are append-only; a mistake is voided.
 *
 * Food safety (docs/07 §2): a treatment's meat and milk withdrawal days set
 * the animal's withdrawal dates. Milk or eggs from an animal under
 * withdrawal must be recorded as discarded and never join a product lot.
 * Production goes into one `animal_product` lot per product per day,
 * derived from the animals that contributed.
 */
class AnimalRecords
{
    public const TYPES = [
        'health' => HealthRecord::class,
        'feeding' => Feeding::class,
        'weight' => Weight::class,
        'production' => ProductionRecord::class,
        'movement' => Movement::class,
    ];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly AuditLogger $audit,
        private readonly ScopedAccess $access,
        private readonly Units $units,
    ) {}

    public function health(array $data): HealthRecord
    {
        [$animal, $group] = $this->subject($data);
        $this->assertInputBatch($data['input_batch_id'] ?? null, 'input_batch_id');

        return DB::transaction(function () use ($data, $animal, $group) {
            $record = HealthRecord::create($this->base($data, $animal, $group) + array_intersect_key($data, array_flip([
                'kind', 'given_on', 'diagnosis', 'product_name', 'dose', 'dose_unit', 'input_batch_id',
                'meat_withdrawal_days', 'milk_withdrawal_days', 'next_due_on', 'given_by',
            ])));

            $event = match ($record->kind) {
                HealthKind::Vaccination => 'vaccinated',
                HealthKind::Deworming => 'dewormed',
                HealthKind::Treatment => 'treated',
                HealthKind::Checkup => 'health_check',
                default => 'health_event',
            };
            foreach ($this->animalsOf($animal, $group) as $a) {
                $this->extendWithdrawal($a, $record);
                $this->event($a, $event, $record, EventTime::on($record->given_on, 9), [
                    'kind' => $record->kind->value,
                    'diagnosis' => $record->diagnosis,
                    'product' => $record->product_name,
                    'dose' => $record->dose,
                    'dose_unit' => $record->dose_unit,
                    'meat_withdrawal_days' => $record->meat_withdrawal_days,
                    'milk_withdrawal_days' => $record->milk_withdrawal_days,
                    'input_batch' => $record->input_batch_id ? TraceBatch::whereKey($record->input_batch_id)->value('batch_code') : null,
                    'group' => $group?->code,
                ]);
            }
            $this->audit->record('livestock.health.recorded', $record, null, ['kind' => $record->kind->value, 'animal' => $animal?->animal_code, 'group' => $group?->code]);

            return $record;
        });
    }

    public function feeding(array $data): Feeding
    {
        [$animal, $group] = $this->subject($data);
        $this->assertInputBatch($data['input_batch_id'] ?? null, 'input_batch_id');

        return DB::transaction(function () use ($data, $animal, $group) {
            $record = Feeding::create($this->base($data, $animal, $group) + array_intersect_key($data, array_flip(['fed_on', 'feed_name', 'quantity', 'unit', 'input_batch_id'])));

            // Only feed from a known lot is worth a trace event; routine
            // feeding stays in the feeding records.
            if ($record->input_batch_id) {
                $code = TraceBatch::whereKey($record->input_batch_id)->value('batch_code');
                foreach ($this->animalsOf($animal, $group) as $a) {
                    $this->event($a, 'fed', $record, EventTime::on($record->fed_on, 7), ['feed' => $record->feed_name, 'input_batch' => $code, 'group' => $group?->code]);
                }
            }

            return $record;
        });
    }

    public function weight(array $data): Weight
    {
        [$animal] = $this->subject($data, groupAllowed: false);

        return DB::transaction(function () use ($data, $animal) {
            $record = Weight::create($this->base($data, $animal, null) + array_intersect_key($data, array_flip(['weighed_on', 'weight_kg'])) + ['method' => $data['method'] ?? 'scale']);
            $this->refreshLastWeight($animal);
            $this->event($animal, 'weighed', $record, EventTime::on($record->weighed_on, 8), ['weight_kg' => $record->weight_kg, 'method' => $record->method->value]);

            return $record;
        });
    }

    public function production(array $data): ProductionRecord
    {
        [$animal, $group] = $this->subject($data);
        $day = CarbonImmutable::parse($data['produced_on']);
        $contributors = $this->animalsOf($animal, $group);
        $blocked = $contributors->first(fn (Animal $a) => $this->milkWithheldUntil($a, $day) !== null);
        $discarded = (bool) ($data['discarded'] ?? false);
        if ($blocked && ! $discarded) {
            $until = $this->milkWithheldUntil($blocked, $day)->toDateString();
            throw new ApiException(422, 'withdrawal_period', "{$blocked->animal_code} is under a withdrawal period until {$until}. Record the {$data['product']} as discarded.", [
                'withdrawal_until' => $until,
            ]);
        }

        return DB::transaction(function () use ($data, $animal, $group, $day, $contributors, $discarded) {
            $lot = $discarded ? null : $this->dayLot($data['product'], $day, $data['unit']);
            $record = ProductionRecord::create($this->base($data, $animal, $group) + array_intersect_key($data, array_flip(['product', 'produced_on', 'session', 'quantity', 'unit'])) + [
                'discarded' => $discarded,
                'trace_batch_id' => $lot?->id,
            ]);

            if ($lot) {
                $added = $this->units->convert((float) $record->quantity, $record->unit, $lot->unit) ?? (float) $record->quantity;
                $lot->forceFill(['quantity' => number_format((float) $lot->quantity + $added, 3, '.', '')])->save();
                foreach ($contributors as $a) {
                    if ($a->batch && ! TraceBatchLink::where(['parent_batch_id' => $a->trace_batch_id, 'child_batch_id' => $lot->id])->exists()) {
                        $this->recorder->link($a->batch, $lot, LinkType::Derived);
                    }
                }
                $this->recorder->record($lot, 'produced', [
                    'occurred_at' => EventTime::on($day, 18),
                    'subject_type' => 'animal_production',
                    'subject_id' => $record->id,
                    'payload' => array_filter([
                        'animal' => $animal?->animal_code,
                        'group' => $group?->code,
                        'quantity' => $record->quantity,
                        'unit' => $record->unit,
                        'session' => $record->session,
                    ]),
                ]);
            }

            return $record;
        });
    }

    /**
     * Move animals (a list, or a whole group) to a location.
     *
     * @return Collection<int,Movement>
     */
    public function move(array $data): Collection
    {
        $this->access->assertCanRecordOn('livestock.records.record');
        $to = Location::find($data['to_location_id']) ?? throw $this->invalid('to_location_id', 'The selected location does not exist in this farm.');
        $movedAt = CarbonImmutable::parse($data['moved_at'] ?? 'now');

        return DB::transaction(function () use ($data, $to, $movedAt) {
            $records = collect();
            $move = function (?Animal $animal, ?AnimalGroup $group, ?string $from) use ($data, $to, $movedAt, &$records) {
                $records->push(Movement::create($this->base($data, $animal, $group) + [
                    'from_location_id' => $from,
                    'to_location_id' => $to->id,
                    'moved_at' => $movedAt,
                    'reason' => $data['reason'] ?? null,
                ]));
            };

            if (! empty($data['group_id'])) {
                $group = AnimalGroup::find($data['group_id']) ?? throw $this->invalid('group_id', 'The selected group does not exist in this farm.');
                $this->access->assertCanRecordOn('livestock.records.record', ['animal_group' => $group->id]);
                $move(null, $group, $group->location_id);
                $group->forceFill(['location_id' => $to->id])->save();
                foreach ($group->activeAnimals()->with('batch')->get() as $a) {
                    $this->relocate($a, $to, $movedAt, $records->last(), $group->code);
                }
            } else {
                $animals = Animal::with('batch')->whereIn('id', $data['animal_ids'] ?? [])->where('status', AnimalStatus::Active->value)->get();
                if ($animals->count() !== count(array_unique($data['animal_ids'] ?? [])) || $animals->isEmpty()) {
                    throw $this->invalid('animal_ids', 'Choose active animals of this farm.');
                }
                foreach ($animals as $a) {
                    $move($a, null, $a->location_id);
                    $this->relocate($a, $to, $movedAt, $records->last(), null);
                }
            }

            return $records;
        });
    }

    /** Mark a record as entered in error and undo what it changed. */
    public function void(AnimalRecord $record, string $reason): AnimalRecordVoid
    {
        $this->access->assertCanRecordOn('livestock.records.record');
        if (AnimalRecordVoid::where('record_type', $record::recordType())->where('record_id', $record->id)->exists()) {
            throw ApiException::conflict('already_voided', 'This record is already voided.');
        }
        if ($record instanceof Movement) {
            throw ApiException::conflict('not_voidable', 'Movements cannot be voided; record the move back instead.');
        }

        return DB::transaction(function () use ($record, $reason) {
            $void = AnimalRecordVoid::create([
                'record_type' => $record::recordType(),
                'record_id' => $record->id,
                'reason' => $reason,
                'voided_by' => Auth::id(),
            ]);

            $animals = $this->animalsOf($record->animal, $record->group);
            if ($record instanceof Weight && $record->animal) {
                $this->refreshLastWeight($record->animal);
            }
            if ($record instanceof HealthRecord) {
                $animals->each(fn (Animal $a) => $this->recomputeWithdrawal($a));
            }
            if ($record instanceof ProductionRecord && $record->trace_batch_id) {
                $lot = TraceBatch::find($record->trace_batch_id);
                $removed = $this->units->convert((float) $record->quantity, $record->unit, $lot->unit) ?? (float) $record->quantity;
                $lot->forceFill(['quantity' => number_format(max(0, (float) $lot->quantity - $removed), 3, '.', '')])->save();
                $this->recorder->record($lot, 'record_voided', ['subject_type' => 'animal_production', 'subject_id' => $record->id,
                    'payload' => ['record' => 'production', 'quantity' => $record->quantity, 'reason' => $reason]]);
            } else {
                foreach ($animals as $a) {
                    $this->event($a, 'record_voided', $record, CarbonImmutable::now(), ['record' => $record::recordType(), 'reason' => $reason]);
                }
            }
            $this->audit->record('livestock.record.voided', ['type' => $record::recordType(), 'id' => $record->id], null, ['reason' => $reason]);

            return $void;
        });
    }

    /**
     * Resolve and check the subject: one active animal or one group.
     *
     * @return array{0: ?Animal, 1: ?AnimalGroup}
     */
    private function subject(array $data, bool $groupAllowed = true): array
    {
        if (! empty($data['animal_id'])) {
            $animal = Animal::find($data['animal_id']) ?? throw $this->invalid('animal_id', 'The selected animal does not exist in this farm.');
            // Field workers record on the animals (or groups) their tasks point to.
            $this->access->assertCanRecordOn('livestock.records.record', ['animal' => $animal->id, 'animal_group' => $animal->group_id]);
            if (! $animal->isActive()) {
                throw ApiException::conflict('animal_inactive', "{$animal->animal_code} has left the herd.");
            }

            return [$animal, null];
        }
        if ($groupAllowed && ! empty($data['group_id'])) {
            $group = AnimalGroup::find($data['group_id']) ?? throw $this->invalid('group_id', 'The selected group does not exist in this farm.');
            $this->access->assertCanRecordOn('livestock.records.record', ['animal_group' => $group->id]);

            return [null, $group];
        }

        $this->access->assertCanRecordOn('livestock.records.record');

        throw $this->invalid('animal_id', $groupAllowed ? 'Choose an animal or a group.' : 'Choose an animal.');
    }

    /** @return Collection<int,Animal> the animals a record applies to */
    private function animalsOf(?Animal $animal, ?AnimalGroup $group): Collection
    {
        if ($animal) {
            return collect([$animal]);
        }

        return $group ? $group->activeAnimals()->with('batch')->get() : collect();
    }

    private function base(array $data, ?Animal $animal, ?AnimalGroup $group): array
    {
        return [
            'animal_id' => $animal?->id,
            'group_id' => $group?->id,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => Auth::id(),
        ];
    }

    private function event(Animal $animal, string $type, AnimalRecord $record, CarbonImmutable $at, array $payload): void
    {
        if (! $animal->batch) {
            return;
        }
        $this->recorder->record($animal->batch, $type, [
            'occurred_at' => $at,
            'subject_type' => 'animal_'.$record::recordType(),
            'subject_id' => $record->id,
            'payload' => array_filter($payload, fn ($v) => $v !== null),
        ]);
    }

    private function extendWithdrawal(Animal $animal, HealthRecord $record): void
    {
        $given = CarbonImmutable::parse($record->given_on);
        foreach (['meat' => $record->meat_withdrawal_days, 'milk' => $record->milk_withdrawal_days] as $kind => $days) {
            if ($days) {
                $until = $given->addDays($days);
                $column = "{$kind}_withdrawal_until";
                if ($animal->{$column} === null || $until->greaterThan($animal->{$column})) {
                    $animal->forceFill([$column => $until->toDateString()]);
                }
            }
        }
        if ($animal->isDirty()) {
            $animal->saveQuietly();
        }
    }

    /** After a void: withdrawal dates from the remaining treatments. */
    private function recomputeWithdrawal(Animal $animal): void
    {
        $records = HealthRecord::notVoided()
            ->where(fn ($q) => $q->where('animal_id', $animal->id)->when($animal->group_id, fn ($q2) => $q2->orWhere('group_id', $animal->group_id)))
            ->get();
        $until = fn (string $kind) => $records->filter(fn ($r) => $r->{"{$kind}_withdrawal_days"})
            ->map(fn ($r) => CarbonImmutable::parse($r->given_on)->addDays($r->{"{$kind}_withdrawal_days"}))
            ->max()?->toDateString();
        $animal->forceFill(['meat_withdrawal_until' => $until('meat'), 'milk_withdrawal_until' => $until('milk')])->saveQuietly();
    }

    /**
     * The end of the milk withdrawal covering `$day`, if any: a treatment
     * given on or before that day whose withdrawal has not yet run out.
     */
    private function milkWithheldUntil(Animal $animal, CarbonImmutable $day): ?CarbonImmutable
    {
        if ($animal->milk_withdrawal_until === null) {
            return null;   // never treated with a milk withdrawal
        }

        return HealthRecord::notVoided()
            ->where(fn ($q) => $q->where('animal_id', $animal->id)->when($animal->group_id, fn ($q2) => $q2->orWhere('group_id', $animal->group_id)))
            ->where('milk_withdrawal_days', '>', 0)
            ->where('given_on', '<=', $day->toDateString())
            ->get(['given_on', 'milk_withdrawal_days'])
            ->map(fn ($r) => CarbonImmutable::parse($r->given_on)->addDays($r->milk_withdrawal_days))
            ->filter(fn (CarbonImmutable $until) => $day->lessThan($until))
            ->max();
    }

    private function refreshLastWeight(Animal $animal): void
    {
        $latest = Weight::notVoided()->where('animal_id', $animal->id)->orderByDesc('weighed_on')->orderByDesc('created_at')->first();
        $animal->forceFill(['last_weight_kg' => $latest?->weight_kg, 'last_weighed_on' => $latest?->weighed_on])->saveQuietly();
    }

    private function relocate(Animal $animal, Location $to, CarbonImmutable $at, Movement $record, ?string $groupCode): void
    {
        $from = $animal->location_id ? Location::withTrashed()->whereKey($animal->location_id)->value('code') : null;
        $animal->forceFill(['location_id' => $to->id])->saveQuietly();
        $this->event($animal, 'moved', $record, $at, ['from' => $from, 'to' => $to->code, 'group' => $groupCode, 'reason' => $record->reason]);
    }

    /** The day's product lot, created on first use. */
    private function dayLot(string $product, CarbonImmutable $day, string $unit): TraceBatch
    {
        $name = ucfirst($product).' '.$day->toDateString();
        $existing = TraceBatch::where('kind', BatchKind::AnimalProduct->value)
            ->where('source_type', 'animal_production_day')
            ->where('name', $name)
            ->lockForUpdate()
            ->first();

        return $existing ?? $this->recorder->createBatch(BatchKind::AnimalProduct, [
            'name' => $name,
            'quantity' => '0',
            'unit' => $unit,
            'source_type' => 'animal_production_day',
        ], ['occurred_at' => EventTime::on($day, 6), 'payload' => ['product' => $product, 'day' => $day->toDateString()]]);
    }

    private function assertInputBatch(?string $id, string $field): void
    {
        if ($id && ! TraceBatch::whereKey($id)->whereIn('kind', [BatchKind::InputLot->value])->exists()) {
            throw $this->invalid($field, 'Choose an input lot of this farm.');
        }
    }

    private function invalid(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => [$message]]);
    }
}
