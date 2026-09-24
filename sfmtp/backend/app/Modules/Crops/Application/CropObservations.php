<?php

namespace App\Modules\Crops\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Crops\Domain\Enums\ObservationStatus;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Domain\Models\CropObservation;
use App\Modules\Traceability\Application\Recorder;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Scouting findings and their follow-up: open → monitoring → resolved. */
class CropObservations
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly AuditLogger $audit,
        private readonly CropAccess $access,
    ) {}

    public function report(CropCycle $cycle, array $data): CropObservation
    {
        $this->access->assertCanRecordOn('crops.operations.record', ['crop_cycle' => $cycle->id, 'plot' => $cycle->plot_id]);
        if (! $cycle->isOpen()) {
            throw ApiException::conflict('cycle_closed', 'The crop cycle is closed.');
        }

        return DB::transaction(function () use ($cycle, $data) {
            $observation = CropObservation::create($data + [
                'cycle_id' => $cycle->id,
                'observed_at' => now(),
                'status' => ObservationStatus::Open,
                'recorded_by' => Auth::id(),
            ]);
            $this->recorder->record($cycle->workingBatch(), 'observation', [
                'occurred_at' => $observation->observed_at,
                'plot_id' => $cycle->plot_id,
                'latitude' => $observation->latitude,
                'longitude' => $observation->longitude,
                'subject_type' => 'crop_observation',
                'subject_id' => $observation->id,
                'payload' => array_filter([
                    'kind' => $observation->kind->value,
                    'severity' => $observation->severity->value,
                    'title' => $observation->title,
                    'affected_pct' => $observation->affected_pct,
                ], fn ($v) => $v !== null),
            ]);
            $this->audit->record('crops.observation.reported', $observation, null, ['cycle' => $cycle->code, 'kind' => $observation->kind->value, 'severity' => $observation->severity->value]);

            return $observation->refresh();
        });
    }

    /** Change severity or status; resolving is recorded in the trace history. */
    public function update(CropObservation $observation, array $data): CropObservation
    {
        $this->access->assertCanRecordOn('crops.operations.record', ['crop_cycle' => $observation->cycle_id, 'plot' => $observation->loadMissing('cycle')->cycle?->plot_id]);
        if ($observation->status === ObservationStatus::Resolved) {
            throw ApiException::conflict('invalid_state_transition', 'The observation is resolved.');
        }

        return DB::transaction(function () use ($observation, $data) {
            $before = ['status' => $observation->status->value, 'severity' => $observation->severity->value];
            $observation->fill(array_intersect_key($data, array_flip(['severity', 'description', 'affected_pct'])));
            if (isset($data['status'])) {
                $observation->status = ObservationStatus::from($data['status']);
                if ($observation->status === ObservationStatus::Resolved) {
                    $observation->resolved_at = now();
                    $observation->resolution_note = $data['resolution_note'] ?? null;
                }
            }
            $observation->save();

            if ($observation->status === ObservationStatus::Resolved) {
                $cycle = $observation->cycle;
                $this->recorder->record($cycle->workingBatch(), 'observation_resolved', [
                    'plot_id' => $cycle->plot_id, 'subject_type' => 'crop_observation', 'subject_id' => $observation->id,
                    'payload' => array_filter(['title' => $observation->title, 'note' => $observation->resolution_note]),
                ]);
            }
            $this->audit->record('crops.observation.updated', $observation, $before, ['status' => $observation->status->value, 'severity' => $observation->severity->value]);

            return $observation;
        });
    }
}
