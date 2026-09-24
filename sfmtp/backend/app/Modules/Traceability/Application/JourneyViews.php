<?php

namespace App\Modules\Traceability\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Traceability\Contracts\WorkerNames;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use Illuminate\Support\Collection;

/**
 * The traceability views of docs/07 §6 over a batch's lineage: timeline,
 * workers, inputs, sales and locations. The lineage is the batch with its
 * ancestors (backward), its descendants (forward), or both.
 *
 * Nothing here returns money: prices and values stay on the finance and
 * sales pages (docs/07 §5).
 */
class JourneyViews
{
    public const TIMELINE_LIMIT = 500;

    /** Events that put an input (seed, fertiliser, chemical, feed, drug) on a batch. */
    public const INPUT_EVENTS = ['input_applied', 'fed', 'treated', 'vaccinated', 'dewormed'];

    public function __construct(
        private readonly JourneyService $journeys,
        private readonly FarmPermissions $permissions,
    ) {}

    /** @return array<int, string> */
    public function lineage(TraceBatch $batch, string $direction): array
    {
        $ids = [$batch->id];
        if ($direction !== 'forward') {
            $ids = array_merge($ids, $this->journeys->reachableIds($batch->id, 'backward'));
        }
        if ($direction !== 'backward') {
            $ids = array_merge($ids, $this->journeys->reachableIds($batch->id, 'forward'));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Events of the lineage in the order they happened. A correction is
     * folded into the event it corrects: the event shows the corrected
     * values, is marked `corrected`, and keeps the history.
     *
     * @return array{events: array<int, array>, truncated: bool}
     */
    public function timeline(TraceBatch $batch, string $direction = 'backward'): array
    {
        $ids = $this->lineage($batch, $direction);
        $batches = TraceBatch::whereIn('id', $ids)->get(['id', 'batch_code', 'kind', 'name'])->keyBy('id');
        $rows = TraceEvent::whereIn('batch_id', $ids)->orderBy('occurred_at')->orderBy('farm_seq')->limit(self::TIMELINE_LIMIT + 1)->get();
        $truncated = $rows->count() > self::TIMELINE_LIMIT;
        $rows = $rows->take(self::TIMELINE_LIMIT);

        $corrections = $rows->where('event_type', 'correction')->groupBy('corrects_event_id');
        // Corrections of events outside the window still need folding.
        $missing = TraceEvent::whereIn('corrects_event_id', $rows->pluck('id'))->whereNotIn('id', $rows->pluck('id'))->orderBy('farm_seq')->get();
        foreach ($missing as $c) {
            $corrections[$c->corrects_event_id] = ($corrections[$c->corrects_event_id] ?? collect())->push($c);
        }

        $users = $this->userNames($rows->pluck('actor_user_id')->filter()->unique()->values()->all());

        $events = $rows->reject(fn (TraceEvent $e) => $e->event_type === 'correction')
            ->map(function (TraceEvent $e) use ($batches, $corrections, $users) {
                $history = ($corrections[$e->id] ?? collect())->sortBy('farm_seq')->values();
                $payload = $e->payload;
                foreach ($history as $c) {
                    $payload = array_merge($payload, (array) ($c->payload['corrected'] ?? []));
                }
                $b = $batches[$e->batch_id] ?? null;

                return [
                    'id' => $e->id,
                    'event_type' => $e->event_type,
                    'batch' => $b ? ['id' => $b->id, 'batch_code' => $b->batch_code, 'kind' => $b->kind->value, 'name' => $b->name] : null,
                    'occurred_at' => $e->occurred_at->toIso8601ZuluString('microsecond'),
                    'recorded_at' => $e->recorded_at->toIso8601ZuluString('microsecond'),
                    'recorded_late' => $e->recorded_at->diffInSeconds($e->occurred_at, true) > 3600,
                    'actor' => $e->actor_user_id ? ['id' => $e->actor_user_id, 'name' => $users[$e->actor_user_id] ?? null] : null,
                    'worker_id' => $e->worker_id,
                    'plot_id' => $e->plot_id,
                    'location' => $e->latitude === null ? null : ['lat' => (float) $e->latitude, 'lng' => (float) $e->longitude, 'accuracy_m' => $e->gps_accuracy_m === null ? null : (float) $e->gps_accuracy_m],
                    'subject' => $e->subject_type ? ['type' => $e->subject_type, 'id' => $e->subject_id] : null,
                    'payload' => $payload,
                    'corrected' => $history->isNotEmpty(),
                    'original_payload' => $history->isNotEmpty() ? $e->payload : null,
                    'corrections' => $history->map(fn (TraceEvent $c) => [
                        'id' => $c->id,
                        'reason' => $c->payload['reason'] ?? null,
                        'corrected' => $c->payload['corrected'] ?? [],
                        'recorded_at' => $c->recorded_at->toIso8601ZuluString('microsecond'),
                        'actor_user_id' => $c->actor_user_id,
                    ])->all(),
                    'seq' => $e->farm_seq,
                ];
            })->values()->all();

        return ['events' => $events, 'truncated' => $truncated];
    }

    /**
     * Who worked on the batch and its sources: field workers (from task
     * submissions) and the people who recorded events.
     *
     * @return array{workers: array<int, array>, recorders: array<int, array>}
     */
    public function workers(TraceBatch $batch): array
    {
        $ids = $this->lineage($batch, 'backward');
        $codes = TraceBatch::whereIn('id', $ids)->pluck('batch_code', 'id');
        $events = TraceEvent::whereIn('batch_id', $ids)->orderBy('occurred_at')->orderBy('farm_seq')->get();

        $withWorker = $events->whereNotNull('worker_id')->groupBy('worker_id');
        $names = $withWorker->isEmpty() ? [] : app(WorkerNames::class)->describe($withWorker->keys()->all());
        $showNames = $this->permissions->allows('workers.view');

        $workers = $withWorker->map(function (Collection $list, string $workerId) use ($names, $showNames, $codes) {
            $known = $names[$workerId] ?? null;

            return [
                'worker_id' => $workerId,
                'worker_code' => $known['code'] ?? ($list->first()->payload['worker'] ?? null),
                'name' => $showNames ? ($known['name'] ?? null) : null,
                'events' => $list->count(),
                'first_at' => $list->first()->occurred_at->toIso8601ZuluString(),
                'last_at' => $list->last()->occurred_at->toIso8601ZuluString(),
                'activities' => $list->map(fn (TraceEvent $e) => array_filter([
                    'event_id' => $e->id,
                    'event_type' => $e->event_type,
                    'activity_type' => $e->payload['activity_type'] ?? null,
                    'task' => $e->payload['task'] ?? null,
                    'batch_code' => $codes[$e->batch_id] ?? null,
                    'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
                    'has_gps' => $e->latitude !== null,
                ], fn ($v) => $v !== null))->values()->all(),
            ];
        })->sortByDesc('events')->values()->all();

        $byActor = $events->whereNotNull('actor_user_id')->groupBy('actor_user_id');
        $users = $this->userNames($byActor->keys()->all());
        $recorders = $byActor->map(fn (Collection $list, string $userId) => [
            'user_id' => $userId,
            'name' => $users[$userId] ?? null,
            'events' => $list->count(),
            'event_types' => $list->pluck('event_type')->countBy()->sortDesc()->all(),
        ])->sortByDesc('events')->values()->all();

        return ['workers' => $workers, 'recorders' => $recorders];
    }

    /**
     * Seeds and inputs: the seed and input lots the batch came from, and
     * every input applied along the way, with lot numbers and withholding.
     *
     * @return array{lots: array<int, array>, applications: array<int, array>}
     */
    public function inputs(TraceBatch $batch): array
    {
        $ids = $this->lineage($batch, 'backward');
        $lineage = TraceBatch::whereIn('id', $ids)->get()->keyBy('id');

        $applications = TraceEvent::whereIn('batch_id', $ids)->whereIn('event_type', self::INPUT_EVENTS)
            ->orderBy('occurred_at')->orderBy('farm_seq')->get();
        $appliedCodes = $applications->pluck('payload.input_batch')->filter()->unique()->values();
        $appliedLots = TraceBatch::whereIn('batch_code', $appliedCodes)->get()->keyBy('batch_code');

        $lotBatches = $lineage->filter(fn (TraceBatch $b) => in_array($b->kind, [BatchKind::SeedLot, BatchKind::InputLot], true))
            ->concat($appliedLots->values())->unique('id');
        $created = TraceEvent::whereIn('batch_id', $lotBatches->pluck('id'))->where('event_type', 'created')->get()->keyBy('batch_id');

        $lots = $lotBatches->map(function (TraceBatch $b) use ($created, $lineage) {
            $p = $created->get($b->id)?->payload ?? [];

            return [
                'batch' => ['id' => $b->id, 'batch_code' => $b->batch_code, 'kind' => $b->kind->value, 'name' => $b->name, 'status' => $b->status->value],
                'role' => $lineage->has($b->id) ? 'source' : 'applied',
                'item' => $p['item'] ?? null,
                'lot_number' => $p['lot_number'] ?? null,
                'supplier' => $p['supplier'] ?? null,
                'order' => $p['order'] ?? null,
                'expires_on' => $p['expires_on'] ?? null,
                'received_at' => $created->get($b->id)?->occurred_at?->toIso8601ZuluString(),
            ];
        })->values()->all();

        return [
            'lots' => $lots,
            'applications' => $applications->map(fn (TraceEvent $e) => [
                'event_id' => $e->id,
                'event_type' => $e->event_type,
                'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
                'applied_to' => $lineage->get($e->batch_id)?->batch_code,
                'product' => $e->payload['product'] ?? $e->payload['feed'] ?? null,
                'quantity' => $e->payload['quantity'] ?? $e->payload['dose'] ?? null,
                'unit' => $e->payload['unit'] ?? $e->payload['dose_unit'] ?? null,
                'input_batch' => ($code = $e->payload['input_batch'] ?? null) ? ['batch_code' => $code, 'id' => $appliedLots->get($code)?->id] : null,
                'withholding_days' => $e->payload['withholding_days'] ?? null,
                'meat_withdrawal_days' => $e->payload['meat_withdrawal_days'] ?? null,
                'milk_withdrawal_days' => $e->payload['milk_withdrawal_days'] ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * Where the batch went: every shipment downstream, its customer and
     * delivery, and which batches it was made of.
     *
     * @return array<int, array>
     */
    public function sales(TraceBatch $batch): array
    {
        $ids = $this->lineage($batch, 'forward');
        $shipments = TraceBatch::whereIn('id', $ids)->where('kind', BatchKind::Shipment->value)->orderBy('created_at')->get();
        if ($shipments->isEmpty()) {
            return [];
        }
        $events = TraceEvent::whereIn('batch_id', $shipments->pluck('id'))->whereIn('event_type', ['dispatched', 'delivered', 'delivery_failed'])
            ->orderBy('farm_seq')->get()->groupBy('batch_id');
        $walk = $this->journeys->walk($batch, 'forward');
        $codes = TraceBatch::whereIn('id', $ids)->pluck('batch_code', 'id');
        $codes[$batch->id] = $batch->batch_code;

        return $shipments->map(function (TraceBatch $s) use ($events, $walk, $codes) {
            $list = $events[$s->id] ?? collect();
            $dispatched = $list->firstWhere('event_type', 'dispatched');
            $delivered = $list->firstWhere('event_type', 'delivered');
            $p = $dispatched?->payload ?? [];

            return [
                'shipment_batch' => ['id' => $s->id, 'batch_code' => $s->batch_code, 'name' => $s->name, 'status' => $s->status->value,
                    'quantity' => $s->quantity === null ? null : ['value' => $s->quantity, 'unit' => $s->unit]],
                'shipment' => $s->source_type === 'shipment' ? ['id' => $s->source_id, 'code' => $p['shipment'] ?? null] : null,
                'customer' => $p['customer'] ?? null,
                'destination' => $p['destination'] ?? null,
                'invoice' => $p['invoice'] ?? null,
                'dispatched_at' => $dispatched?->occurred_at?->toIso8601ZuluString(),
                'delivered_at' => $delivered?->occurred_at?->toIso8601ZuluString(),
                'received_by' => $delivered?->payload['received_by'] ?? null,
                'from' => collect($walk['edges'])->where('to', $s->id)->where('link_type', 'ship')
                    ->map(fn ($e) => ['batch_id' => $e['from'], 'batch_code' => $codes[$e['from']] ?? null, 'quantity' => $e['quantity'], 'unit' => $e['unit']])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * The geographic trail: plots the lineage grew on or was worked on, GPS
     * points of events, and movements between places.
     *
     * @return array{plots: array<int, array>, points: array<int, array>, moves: array<int, array>}
     */
    public function locations(TraceBatch $batch): array
    {
        $ids = $this->lineage($batch, 'both');
        $lineage = TraceBatch::whereIn('id', $ids)->get(['id', 'batch_code', 'kind', 'origin_plot_id'])->keyBy('id');
        $events = TraceEvent::whereIn('batch_id', $ids)
            ->where(fn ($q) => $q->whereNotNull('plot_id')->orWhereNotNull('latitude')->orWhere('event_type', 'moved'))
            ->orderBy('occurred_at')->orderBy('farm_seq')->get();

        $plotBatches = [];
        foreach ($lineage as $b) {
            if ($b->origin_plot_id) {
                $plotBatches[$b->origin_plot_id][$b->batch_code] = true;
            }
        }
        foreach ($events->whereNotNull('plot_id') as $e) {
            $plotBatches[$e->plot_id][$lineage[$e->batch_id]->batch_code] = true;
        }
        $plots = Plot::whereIn('id', array_keys($plotBatches))->get();

        return [
            'plots' => $plots->map(fn (Plot $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'area_ha' => $p->area_ha ?? $p->declared_area_ha,
                'centroid' => $p->centroid_lat === null ? null : ['lat' => (float) $p->centroid_lat, 'lng' => (float) $p->centroid_lng],
                'boundary' => $p->boundary,
                'batches' => array_keys($plotBatches[$p->id]),
            ])->values()->all(),
            'points' => $events->whereNotNull('latitude')->map(fn (TraceEvent $e) => [
                'event_id' => $e->id,
                'event_type' => $e->event_type,
                'batch_code' => $lineage[$e->batch_id]->batch_code,
                'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
                'lat' => (float) $e->latitude,
                'lng' => (float) $e->longitude,
                'accuracy_m' => $e->gps_accuracy_m === null ? null : (float) $e->gps_accuracy_m,
                'worker_id' => $e->worker_id,
            ])->values()->all(),
            'moves' => $events->where('event_type', 'moved')->map(fn (TraceEvent $e) => [
                'event_id' => $e->id,
                'batch_code' => $lineage[$e->batch_id]->batch_code,
                'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
                'from' => $e->payload['from'] ?? null,
                'to' => $e->payload['to'] ?? null,
            ])->values()->all(),
        ];
    }

    /** @return array{events:int, gps_points:int, photos:int, workers:int, corrections:int} */
    public function evidence(array $batchIds): array
    {
        $q = fn () => TraceEvent::whereIn('batch_id', $batchIds);

        return [
            'events' => $q()->count(),
            'gps_points' => $q()->whereNotNull('latitude')->count(),
            'workers' => $q()->whereNotNull('worker_id')->distinct()->count('worker_id'),
            'corrections' => $q()->where('event_type', 'correction')->count(),
        ];
    }

    /** @return array<string, string> */
    private function userNames(array $ids): array
    {
        return $ids ? User::whereIn('id', $ids)->pluck('name', 'id')->all() : [];
    }
}
