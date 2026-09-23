<?php

namespace App\Modules\Traceability\Http\Resources;

use App\Modules\Traceability\Domain\Models\TraceEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TraceEvent */
class TraceEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $recordedLate = $this->recorded_at->diffInSeconds($this->occurred_at, true) > 3600;

        return [
            'id' => $this->id,
            'type' => 'trace_event',
            'batch_id' => $this->batch_id,
            'event_type' => $this->event_type,
            'occurred_at' => $this->occurred_at->toIso8601ZuluString('microsecond'),
            'recorded_at' => $this->recorded_at->toIso8601ZuluString('microsecond'),
            'recorded_late' => $recordedLate,
            'actor_user_id' => $this->actor_user_id,
            'worker_id' => $this->worker_id,
            'plot_id' => $this->plot_id,
            'location' => $this->latitude === null ? null : [
                'lat' => (float) $this->latitude,
                'lng' => (float) $this->longitude,
                'accuracy_m' => $this->gps_accuracy_m === null ? null : (float) $this->gps_accuracy_m,
            ],
            'subject' => $this->subject_type ? ['type' => $this->subject_type, 'id' => $this->subject_id] : null,
            'payload' => $this->payload,
            'corrects_event_id' => $this->corrects_event_id,
            'integrity' => ['seq' => $this->farm_seq, 'hash' => $this->hash, 'prev_hash' => $this->prev_hash],
        ];
    }
}
