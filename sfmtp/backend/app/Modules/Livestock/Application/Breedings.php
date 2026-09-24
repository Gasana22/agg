<?php

namespace App\Modules\Livestock\Application;

use App\Modules\Access\Application\ScopedAccess;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Livestock\Domain\Enums\BreedingStatus;
use App\Modules\Livestock\Domain\Enums\Gestation;
use App\Modules\Livestock\Domain\Enums\Origin;
use App\Modules\Livestock\Domain\Enums\Sex;
use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\Breeding;
use App\Modules\Traceability\Application\Recorder;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Services and births: served → pregnant | not_pregnant, pregnant →
 * delivered | aborted. A birth registers each offspring with its dam and
 * sire, so the calf's trace batch is derived from its parents'.
 */
class Breedings
{
    private const NEXT = [
        'served' => ['pregnant', 'not_pregnant', 'delivered', 'aborted'],
        'pregnant' => ['delivered', 'aborted'],
    ];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly AuditLogger $audit,
        private readonly ScopedAccess $access,
        private readonly Herd $herd,
    ) {}

    public function serve(array $data): Breeding
    {
        $this->access->assertCanRecordOn('livestock.records.record');
        $dam = Animal::find($data['dam_id']);
        if ($dam === null || $dam->sex !== Sex::Female || ! $dam->isActive()) {
            throw $this->invalid('dam_id', 'Choose an active female of this farm.');
        }
        if (! empty($data['sire_id'])) {
            $sire = Animal::find($data['sire_id']);
            if ($sire === null || $sire->sex !== Sex::Male || $sire->species_id !== $dam->species_id) {
                throw $this->invalid('sire_id', 'Choose a male of the same species from this farm.');
            }
        }
        if (Breeding::where('dam_id', $dam->id)->whereIn('status', ['served', 'pregnant'])->exists()) {
            throw ApiException::conflict('breeding_open', "{$dam->animal_code} already has an open service. Record its outcome first.");
        }

        return DB::transaction(function () use ($data, $dam) {
            $served = CarbonImmutable::parse($data['served_on']);
            $gestation = Gestation::days($dam->speciesCode());
            $breeding = Breeding::create($data + [
                'expected_due_on' => $gestation ? $served->addDays($gestation)->toDateString() : null,
                'recorded_by' => Auth::id(),
            ]);
            $this->recorder->record($dam->batch, 'served', [
                'occurred_at' => $served->setTime(10, 0),
                'subject_type' => 'animal_breeding',
                'subject_id' => $breeding->id,
                'payload' => array_filter([
                    'method' => $breeding->method->value,
                    'sire' => $breeding->sire?->animal_code,
                    'sire_note' => $breeding->sire_note,
                    'expected_due_on' => $breeding->expected_due_on?->toDateString(),
                ]),
            ]);
            $this->audit->record('livestock.breeding.served', $breeding, null, ['dam' => $dam->animal_code, 'method' => $breeding->method->value]);

            return $breeding->refresh();
        });
    }

    /** Pregnancy diagnosis or loss. */
    public function update(Breeding $breeding, BreedingStatus $status, ?string $on, ?string $note): Breeding
    {
        $this->access->assertCanRecordOn('livestock.records.record');
        if ($status === BreedingStatus::Delivered) {
            throw $this->invalid('status', 'Record the birth instead.');
        }
        $this->assertTransition($breeding, $status);

        return DB::transaction(function () use ($breeding, $status, $on, $note) {
            $from = $breeding->status->value;
            $breeding->forceFill(['status' => $status, 'outcome_on' => $on ?? now()->toDateString(), 'notes' => $note ?? $breeding->notes])->save();
            $event = match ($status) {
                BreedingStatus::Pregnant => 'pregnancy_confirmed',
                BreedingStatus::NotPregnant => 'not_pregnant',
                default => 'aborted',
            };
            $this->recorder->record($breeding->dam->batch, $event, [
                'occurred_at' => CarbonImmutable::parse($breeding->outcome_on)->setTime(10, 0),
                'subject_type' => 'animal_breeding',
                'subject_id' => $breeding->id,
                'payload' => array_filter(['note' => $note]),
            ]);
            $this->audit->record('livestock.breeding.updated', $breeding, ['status' => $from], ['status' => $status->value]);

            return $breeding;
        });
    }

    /**
     * Record a birth and register the offspring.
     *
     * @param  array<int,array{sex:string, name?:?string, tag_number?:?string, birth_weight_kg?:?float}>  $offspring
     * @return Collection<int,Animal>
     */
    public function birth(Breeding $breeding, string $bornOn, array $offspring, ?string $note): Collection
    {
        $this->access->assertCanRecordOn('livestock.records.record');
        $this->assertTransition($breeding, BreedingStatus::Delivered);
        $dam = $breeding->dam;

        return DB::transaction(function () use ($breeding, $bornOn, $offspring, $note, $dam) {
            $born = collect();
            foreach ($offspring as $young) {
                $born->push($this->herd->register([
                    'species_id' => $dam->species_id,
                    'breed_id' => $breeding->sire && $breeding->sire->breed_id === $dam->breed_id ? $dam->breed_id : null,
                    'breed_note' => $breeding->sire && $breeding->sire->breed_id !== $dam->breed_id ? 'Cross' : null,
                    'sex' => $young['sex'],
                    'name' => $young['name'] ?? null,
                    'tag_number' => $young['tag_number'] ?? null,
                    'birth_date' => $bornOn,
                    'origin' => Origin::Born->value,
                    'dam_id' => $dam->id,
                    'sire_id' => $breeding->sire_id,
                    'parentage_note' => $breeding->sire_id ? null : $breeding->sire_note,
                    'group_id' => $dam->group_id,
                    'location_id' => $dam->location_id,
                ], CarbonImmutable::parse($bornOn)->setTime(6, 0)));
            }

            $breeding->forceFill(['status' => BreedingStatus::Delivered, 'outcome_on' => $bornOn, 'offspring_count' => $born->count(), 'notes' => $note ?? $breeding->notes])->save();
            $this->recorder->record($dam->batch, 'gave_birth', [
                'occurred_at' => CarbonImmutable::parse($bornOn)->setTime(6, 0),
                'subject_type' => 'animal_breeding',
                'subject_id' => $breeding->id,
                'payload' => ['offspring' => $born->pluck('animal_code')->all()],
            ]);
            $this->audit->record('livestock.breeding.birth', $breeding, null, ['dam' => $dam->animal_code, 'offspring' => $born->pluck('animal_code')->all()]);

            return $born;
        });
    }

    private function assertTransition(Breeding $breeding, BreedingStatus $to): void
    {
        if (! in_array($to->value, self::NEXT[$breeding->status->value] ?? [], true)) {
            throw ApiException::conflict('invalid_state_transition', "A breeding cannot move from {$breeding->status->value} to {$to->value}.");
        }
    }

    private function invalid(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => [$message]]);
    }
}
