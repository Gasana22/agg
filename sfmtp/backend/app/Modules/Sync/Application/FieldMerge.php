<?php

namespace App\Modules\Sync\Application;

use App\Modules\Access\Application\ScopedAccess;
use App\Modules\Livestock\Application\Herd;
use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Http\Controllers\AnimalController;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Sync\Domain\Models\SyncConflict;
use App\Support\Http\ApiException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Offline edits of mutable master data (docs/08 §4). The phone sends the
 * fields it changed, their values when it last synced (`base`) and the
 * record version it saw. Against the server's current record, per field:
 * - unchanged on the server since `base`: the phone's value is applied;
 * - already equal to the phone's value: nothing to do;
 * - changed on both sides to different values: a conflict the member
 *   resolves (keep mine / keep the server's).
 * Fields changed on one side only are merged automatically.
 */
class FieldMerge
{
    /** Animal fields a phone may edit. Group moves go through movements. */
    public const ANIMAL_FIELDS = ['name', 'tag_number', 'rfid', 'breed_id', 'breed_note', 'birth_date', 'birth_date_estimated', 'parentage_note', 'acquired_on', 'notes'];

    public function __construct(
        private readonly Herd $herd,
        private readonly ScopedAccess $access,
        private readonly Inbox $inbox,
    ) {}

    /**
     * @param  array{id?:string, changes?:array<string,mixed>, base?:array<string,mixed>}  $ctx
     * @return array<string, mixed> the push result
     */
    public function animal(array $ctx, ?int $baseVersion, string $mutationId): array
    {
        $changes = array_intersect_key((array) ($ctx['changes'] ?? []), array_flip(self::ANIMAL_FIELDS));
        if ($changes === []) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['changes' => ['Send at least one field to change: '.implode(', ', self::ANIMAL_FIELDS).'.']]);
        }
        Validator::make($changes, array_intersect_key(AnimalController::rules(false), $changes))->validate();
        $base = (array) ($ctx['base'] ?? []);
        $animal = $this->visible($ctx['id'] ?? '');

        $apply = [];
        $conflicts = [];
        foreach ($changes as $field => $mine) {
            $server = self::value($animal, $field);
            if (self::same($server, $mine)) {
                continue;
            }
            if ($baseVersion === null || $baseVersion === (int) $animal->version || (array_key_exists($field, $base) && self::same($server, $base[$field]))) {
                $apply[$field] = $mine;
            } else {
                $conflicts[] = ['field' => $field, 'base' => $base[$field] ?? null, 'mine' => $mine, 'server' => $server];
            }
        }

        if ($apply) {
            $animal = $this->herd->update($animal, $apply);
        }
        $animal = $this->visible($animal->id);
        if ($conflicts === []) {
            return ['status' => 'applied', 'merged' => array_keys($apply), 'animal' => $animal];
        }

        $conflict = SyncConflict::create([
            'user_id' => Auth::id(),
            'mutation_id' => $mutationId,
            'entity' => 'animals',
            'record_id' => $animal->id,
            'label' => trim($animal->animal_code.' '.($animal->name ?? '')),
            'base_version' => $baseVersion,
            'server_version' => $animal->version,
            'fields' => $conflicts,
            'status' => 'open',
        ]);
        $this->inbox->notify([Auth::id()], 'sync_conflict', "Choose which details to keep for {$conflict->label}",
            'Someone else changed '.implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c['field']), $conflicts)).' while you were offline.',
            null, ['conflict_id' => $conflict->id, 'animal_id' => $animal->id]);

        return ['status' => 'conflict', 'merged' => array_keys($apply), 'conflict' => $conflict, 'animal' => $animal];
    }

    /**
     * Resolve a conflict: for each field keep the member's value (`mine`) or
     * the server's (`server`). Keeping mine writes it now.
     *
     * @param  array<string, string>  $choices
     */
    public function resolve(SyncConflict $conflict, array $choices): SyncConflict
    {
        if ($conflict->user_id !== Auth::id()) {
            throw ApiException::forbidden('forbidden', 'Only the person whose change it was can resolve this conflict.');
        }
        if ($conflict->status !== 'open') {
            throw ApiException::conflict('invalid_state_transition', 'This conflict is already resolved.');
        }
        $fields = collect($conflict->fields)->keyBy('field');
        $missing = $fields->keys()->diff(array_keys($choices));
        $bad = collect($choices)->reject(fn ($v, $k) => $fields->has($k) && in_array($v, ['mine', 'server'], true));
        if ($missing->isNotEmpty() || $bad->isNotEmpty()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['choices' => ['Choose mine or server for: '.$fields->keys()->implode(', ').'.']]);
        }

        return DB::transaction(function () use ($conflict, $choices, $fields) {
            $mine = [];
            foreach ($choices as $field => $choice) {
                if ($choice === 'mine') {
                    $mine[$field] = $fields[$field]['mine'];
                }
            }
            if ($mine) {
                $this->herd->update($this->visible($conflict->record_id), $mine);
            }
            $conflict->forceFill(['status' => 'resolved', 'resolution' => $choices, 'resolved_at' => now()])->save();

            return $conflict;
        });
    }

    private function visible(string $id): Animal
    {
        return $this->access->scoped(Animal::query(), 'livestock.animals.view', 'created_by')->whereKey($id)->first() ?? throw ApiException::notFound();
    }

    private static function value(Animal $animal, string $field): mixed
    {
        $v = $animal->getAttribute($field);

        return $v instanceof CarbonInterface ? $v->toDateString() : $v;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return self::norm($a) === self::norm($b);
    }

    private static function norm(mixed $v): ?string
    {
        return match (true) {
            $v === null, $v === '' => null,
            is_bool($v) => $v ? '1' : '0',
            $v instanceof CarbonInterface => $v->toDateString(),
            is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}(T|\s|$)/', $v) === 1 => substr($v, 0, 10),
            default => trim((string) $v),
        };
    }
}
