<?php

namespace App\Modules\Livestock\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Enums\Origin;
use App\Modules\Livestock\Domain\Enums\Sex;
use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\AnimalGroup;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Support\Http\ApiException;
use App\Support\Time\EventTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Groups and animals: registration, births, edits and exits. Every animal
 * gets an `animal` trace batch; a calf's batch is derived from its dam's.
 */
class Herd
{
    private const CODE_PREFIX = ['cattle' => 'CAT', 'goat' => 'GOA', 'sheep' => 'SHP', 'pig' => 'PIG', 'chicken' => 'CHK', 'rabbit' => 'RAB'];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly AuditLogger $audit,
    ) {}

    public function createGroup(array $data): AnimalGroup
    {
        $this->assertLocation($data['location_id'] ?? null);
        $code = strtoupper($data['code'] ?? '') ?: $this->nextCode(AnimalGroup::class, 'code', 'GRP');
        if (AnimalGroup::where('code', $code)->exists()) {
            throw $this->invalid('code', 'This group code is already used in this farm.');
        }
        $group = AnimalGroup::create(['code' => $code] + $data);
        $this->audit->record('livestock.group.created', $group, null, $group->only(['code', 'name', 'purpose']));

        return $group->refresh();
    }

    public function updateGroup(AnimalGroup $group, array $data): AnimalGroup
    {
        $this->assertLocation($data['location_id'] ?? null);
        $before = $group->only(array_keys($data));
        $group->fill($data)->save();
        if ($group->wasChanged()) {
            $this->audit->record('livestock.group.updated', $group, $before, $group->only(array_keys($data)));
        }

        return $group;
    }

    /**
     * Register an animal already on the farm, bought in, or born here.
     *
     * @param  array<string,mixed>  $data  validated
     */
    public function register(array $data, ?CarbonImmutable $occurredAt = null): Animal
    {
        $species = DB::table('global_animal_species')->where('id', $data['species_id'])->first() ?? throw $this->invalid('species_id', 'Unknown species.');
        if (! empty($data['breed_id']) && ! DB::table('global_animal_breeds')->where('id', $data['breed_id'])->where('species_id', $species->id)->exists()) {
            throw $this->invalid('breed_id', 'Choose a breed of this species.');
        }
        $dam = $this->parent($data['dam_id'] ?? null, Sex::Female, $species->id, 'dam_id');
        $sire = $this->parent($data['sire_id'] ?? null, Sex::Male, $species->id, 'sire_id');
        $group = null;
        if (! empty($data['group_id'])) {
            $group = AnimalGroup::find($data['group_id']) ?? throw $this->invalid('group_id', 'The selected group does not exist in this farm.');
            if ($group->species_id !== $species->id) {
                throw $this->invalid('group_id', 'The group holds another species.');
            }
        }
        $this->assertLocation($data['location_id'] ?? null);
        if (! empty($data['tag_number']) && Animal::where('tag_number', $data['tag_number'])->where('status', AnimalStatus::Active->value)->exists()) {
            throw $this->invalid('tag_number', 'An active animal already carries this tag.');
        }

        return DB::transaction(function () use ($data, $species, $dam, $sire, $group, $occurredAt) {
            $animal = new Animal($data);
            $animal->animal_code = $this->nextCode(Animal::class, 'animal_code', self::CODE_PREFIX[$species->code] ?? strtoupper(substr($species->code, 0, 3)));
            $animal->location_id ??= $group?->location_id;
            $animal->created_by = Auth::id();
            $animal->save();

            $born = $animal->origin === Origin::Born;
            $when = $occurredAt ?? CarbonImmutable::parse(($born ? $animal->birth_date : $animal->acquired_on) ?? 'now');
            $batch = $this->recorder->createBatch(BatchKind::Animal, [
                'name' => mb_substr("{$species->name} {$animal->label()}", 0, 150),
                'quantity' => '1',
                'unit' => 'head',
                'source_type' => 'animal',
                'source_id' => $animal->id,
            ], ['occurred_at' => $when, 'subject_type' => 'animal', 'subject_id' => $animal->id]);
            $animal->forceFill(['trace_batch_id' => $batch->id])->saveQuietly();

            foreach (array_filter([$dam, $sire]) as $parent) {
                if ($parent->batch) {
                    $this->recorder->link($parent->batch, $batch, LinkType::Derived);
                }
            }
            $this->recorder->record($batch, $born ? 'born' : 'registered', [
                'occurred_at' => $when,
                'subject_type' => 'animal',
                'subject_id' => $animal->id,
                'payload' => array_filter([
                    'code' => $animal->animal_code,
                    'tag' => $animal->tag_number,
                    'species' => $species->name,
                    'sex' => $animal->sex->value,
                    'origin' => $animal->origin->value,
                    'dam' => $dam?->animal_code,
                    'sire' => $sire?->animal_code,
                    'parentage' => $animal->parentage_note,
                ]),
            ]);
            $this->audit->record('livestock.animal.registered', $animal, null, $animal->only(['animal_code', 'tag_number', 'sex', 'origin', 'group_id']));

            return $animal->refresh();
        });
    }

    public function update(Animal $animal, array $data): Animal
    {
        if (! $animal->isActive()) {
            throw ApiException::conflict('animal_inactive', 'This animal has left the herd.');
        }
        if (array_key_exists('group_id', $data) && $data['group_id']) {
            $group = AnimalGroup::find($data['group_id']) ?? throw $this->invalid('group_id', 'The selected group does not exist in this farm.');
            if ($group->species_id !== $animal->species_id) {
                throw $this->invalid('group_id', 'The group holds another species.');
            }
        }
        if (! empty($data['tag_number']) && Animal::where('tag_number', $data['tag_number'])->where('status', AnimalStatus::Active->value)->whereKeyNot($animal->id)->exists()) {
            throw $this->invalid('tag_number', 'An active animal already carries this tag.');
        }

        $before = $animal->only(array_keys($data));
        $animal->fill($data)->save();
        if ($animal->wasChanged()) {
            $this->audit->record('livestock.animal.updated', $animal, $before, $animal->only(array_keys($data)));
            if ($animal->wasChanged(['tag_number', 'group_id'])) {
                $this->recorder->record($animal->batch, 'details_changed', ['subject_type' => 'animal', 'subject_id' => $animal->id,
                    'payload' => array_filter(['tag' => $animal->wasChanged('tag_number') ? $animal->tag_number : null, 'group' => $animal->wasChanged('group_id') ? $animal->group?->code : null])]);
            }
        }

        return $animal;
    }

    /** Death, culling or transfer off the farm. Sales go through sale requests. */
    public function exit(Animal $animal, AnimalStatus $status, string $date, string $reason): Animal
    {
        if (! $animal->isActive()) {
            throw ApiException::conflict('animal_inactive', 'This animal has already left the herd.');
        }
        if (! in_array($status, [AnimalStatus::Dead, AnimalStatus::Culled, AnimalStatus::Transferred], true)) {
            throw $this->invalid('status', 'Use a sale request to sell an animal.');
        }

        return DB::transaction(function () use ($animal, $status, $date, $reason) {
            $animal->forceFill(['status' => $status, 'exited_on' => $date, 'exit_reason' => $reason])->save();
            $event = match ($status) {
                AnimalStatus::Dead => 'died',
                AnimalStatus::Culled => 'culled',
                default => 'transferred',
            };
            $this->closeBatch($animal, $event, $date, ['reason' => $reason]);
            $this->audit->record("livestock.animal.{$event}", $animal, ['status' => 'active'], ['status' => $status->value, 'on' => $date, 'reason' => $reason]);

            return $animal;
        });
    }

    /** Record the exit event on the animal's batch and close it. */
    public function closeBatch(Animal $animal, string $event, string $date, array $payload): void
    {
        $batch = $animal->batch;
        if ($batch === null) {
            return;
        }
        $this->recorder->record($batch, $event, [
            'occurred_at' => EventTime::on($date, 12),
            'subject_type' => 'animal',
            'subject_id' => $animal->id,
            'payload' => array_filter($payload),
        ]);
        if ($batch->status === BatchStatus::Open) {
            $this->recorder->changeStatus($batch, BatchStatus::Closed, "Animal {$animal->animal_code}: {$event}");
        }
    }

    private function parent(?string $id, Sex $sex, string $speciesId, string $field): ?Animal
    {
        if (! $id) {
            return null;
        }
        $parent = Animal::find($id);
        if ($parent === null || $parent->sex !== $sex || $parent->species_id !== $speciesId) {
            throw $this->invalid($field, 'Choose a '.($sex === Sex::Female ? 'female' : 'male').' of the same species from this farm.');
        }

        return $parent;
    }

    private function assertLocation(?string $locationId): void
    {
        if ($locationId && ! Location::whereKey($locationId)->exists()) {
            throw $this->invalid('location_id', 'The selected location does not exist in this farm.');
        }
    }

    /** @param  class-string<Model>  $model */
    private function nextCode(string $model, string $column, string $prefix): string
    {
        $n = $model::where($column, 'like', "{$prefix}-%")->count() + 1;
        do {
            $code = $prefix.'-'.str_pad((string) $n++, 3, '0', STR_PAD_LEFT);
        } while ($model::where($column, $code)->exists());

        return $code;
    }

    private function invalid(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => [$message]]);
    }
}
