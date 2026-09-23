<?php

namespace App\Modules\Traceability\Application;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceBatchLink;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of traceability data (docs/07 §2).
 *
 * Domain modules call it from their services, inside their own transaction,
 * so a business action and its trace event commit or fail together.
 */
class Recorder
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BatchCodeGenerator $codes,
    ) {}

    /**
     * @param  array{name?:string, quantity?:string|float|null, unit?:string|null, product_id?:string, origin_plot_id?:string, source_type?:string, source_id?:string}  $attributes
     * @param  array<string,mixed>  $event  optional details for the `created` event (occurred_at, payload, gps …)
     */
    public function createBatch(BatchKind $kind, array $attributes = [], array $event = []): TraceBatch
    {
        return DB::transaction(function () use ($kind, $attributes, $event) {
            $batch = TraceBatch::create([
                'batch_code' => $this->codes->generate(),
                'kind' => $kind,
                'status' => BatchStatus::Open,
                'created_by' => $this->actorId(),
            ] + array_intersect_key($attributes, array_flip([
                'name', 'quantity', 'unit', 'product_id', 'origin_plot_id', 'source_type', 'source_id',
            ])));

            $this->record($batch, 'created', $event + ['payload' => array_filter([
                'kind' => $kind->value,
                'name' => $batch->name,
                'quantity' => $batch->quantity,
                'unit' => $batch->unit,
            ], fn ($v) => $v !== null)]);

            return $batch;
        });
    }

    /**
     * Add a directed edge parent → child. Rejects links that would create a cycle.
     */
    public function link(TraceBatch $parent, TraceBatch $child, LinkType $type, ?string $quantity = null, ?string $unit = null, array $event = []): TraceBatchLink
    {
        if ($parent->id === $child->id) {
            throw ApiException::unprocessable('trace_link_self', 'A batch cannot be linked to itself.');
        }

        return DB::transaction(function () use ($parent, $child, $type, $quantity, $unit, $event) {
            if (in_array($parent->id, $this->descendantIds($child->id), true)) {
                throw ApiException::unprocessable('trace_link_cycle', 'This link would make a batch its own ancestor.');
            }

            if (TraceBatchLink::where(['parent_batch_id' => $parent->id, 'child_batch_id' => $child->id, 'link_type' => $type->value])->exists()) {
                throw ApiException::conflict('duplicate', 'These batches are already linked this way.');
            }

            $link = TraceBatchLink::create([
                'parent_batch_id' => $parent->id,
                'child_batch_id' => $child->id,
                'link_type' => $type,
                'quantity' => $quantity,
                'unit' => $unit,
                'created_by' => $this->actorId(),
            ]);

            $details = ['link_id' => $link->id, 'link_type' => $type->value, 'quantity' => $quantity, 'unit' => $unit];
            $this->record($child, 'linked_from', $event + ['payload' => ['parent_batch_id' => $parent->id, 'parent_batch_code' => $parent->batch_code] + array_filter($details)]);
            $this->record($parent, 'linked_to', $event + ['payload' => ['child_batch_id' => $child->id, 'child_batch_code' => $child->batch_code] + array_filter($details)]);

            return $link;
        });
    }

    /**
     * Append an event to a batch's history.
     *
     * @param  array{occurred_at?:\DateTimeInterface|string, payload?:array, worker_id?:string, plot_id?:string, latitude?:float|string, longitude?:float|string, gps_accuracy_m?:float|string, subject_type?:string, subject_id?:string, corrects_event_id?:string}  $data
     */
    public function record(TraceBatch $batch, string $eventType, array $data = []): TraceEvent
    {
        return DB::transaction(function () use ($batch, $eventType, $data) {
            $farmId = $this->context->farmId();
            if ($batch->farm_id !== $farmId) {
                throw ApiException::notFound();
            }

            // Lock this farm's chain head so events get consecutive sequence numbers.
            DB::table('trace_sequences')->insertOrIgnore([
                'farm_id' => $farmId,
                'last_seq' => 0,
                'last_hash' => EventHasher::GENESIS,
                'updated_at' => now(),
            ]);
            $head = DB::table('trace_sequences')->where('farm_id', $farmId)->lockForUpdate()->first();

            $occurredAt = isset($data['occurred_at'])
                ? CarbonImmutable::parse($data['occurred_at'])->utc()
                : CarbonImmutable::now('UTC');

            $row = [
                'id' => (string) Str::uuid7(),
                'farm_id' => $farmId,
                'batch_id' => $batch->id,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'recorded_at' => CarbonImmutable::now('UTC'),
                'actor_user_id' => $this->actorId(),
                'worker_id' => $data['worker_id'] ?? null,
                'plot_id' => $data['plot_id'] ?? null,
                // Rounded here to the column precision, so the stored value and
                // the hashed value are identical on every database engine.
                'latitude' => self::round($data['latitude'] ?? null, 7),
                'longitude' => self::round($data['longitude'] ?? null, 7),
                'gps_accuracy_m' => self::round($data['gps_accuracy_m'] ?? null, 2),
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'payload' => EventHasher::normalisePayload($data['payload'] ?? []),
                'corrects_event_id' => $data['corrects_event_id'] ?? null,
                'farm_seq' => $head->last_seq + 1,
                'prev_hash' => $head->last_hash,
            ];
            $row['hash'] = EventHasher::hash($row['prev_hash'], $row);

            $event = TraceEvent::create($row);

            DB::table('trace_sequences')->where('farm_id', $farmId)->update([
                'last_seq' => $row['farm_seq'],
                'last_hash' => $row['hash'],
                'updated_at' => now(),
            ]);

            return $event;
        });
    }

    /**
     * Correct an event by appending a new one that references it (docs/07 §3).
     */
    public function correct(TraceEvent $original, array $correctedPayload, string $reason): TraceEvent
    {
        if ($original->event_type === 'correction') {
            // Keep corrections pointing at the original fact.
            $original = TraceEvent::findOrFail($original->corrects_event_id);
        }

        return $this->record(TraceBatch::findOrFail($original->batch_id), 'correction', [
            'corrects_event_id' => $original->id,
            'subject_type' => $original->subject_type,
            'subject_id' => $original->subject_id,
            'payload' => [
                'corrects_event_type' => $original->event_type,
                'reason' => $reason,
                'corrected' => $correctedPayload,
            ],
        ]);
    }

    public function changeStatus(TraceBatch $batch, BatchStatus $status, string $reason): TraceBatch
    {
        if ($batch->status === $status) {
            throw ApiException::conflict('invalid_state_transition', "The batch is already {$status->value}.");
        }

        return DB::transaction(function () use ($batch, $status, $reason) {
            $from = $batch->status;
            $batch->status = $status;
            $batch->save();
            $this->record($batch, 'status_changed', ['payload' => ['from' => $from->value, 'to' => $status->value, 'reason' => $reason]]);

            return $batch;
        });
    }

    /** @return array<int,string> every batch reachable downstream of $batchId */
    private function descendantIds(string $batchId): array
    {
        return app(JourneyService::class)->reachableIds($batchId, 'forward');
    }

    private static function round(mixed $value, int $places): ?string
    {
        return $value === null ? null : number_format(round((float) $value, $places), $places, '.', '');
    }

    private function actorId(): ?string
    {
        return auth()->id();
    }
}
