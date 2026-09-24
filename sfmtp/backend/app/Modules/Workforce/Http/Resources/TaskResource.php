<?php

namespace App\Modules\Workforce\Http\Resources;

use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A task with enough of its activity for a worker's phone to show it
 * offline. Locations in logs and photos are shown to the worker who
 * recorded them and to members holding worker.gps.view.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $access = app(WorkforceAccess::class);
        $mine = $access->currentWorker()?->id === $this->worker_id;
        $seesPlaces = $mine || $access->can('worker.gps.view');
        $a = $this->relationLoaded('activity') ? $this->activity : null;

        return [
            'id' => $this->id,
            'type' => 'task',
            'code' => $this->code,
            'status' => $this->status->value,
            'is_mine' => $mine,
            'due_on' => $this->due_on?->toDateString(),
            'overdue' => $this->due_on !== null && in_array($this->status->value, TaskStatus::OPEN, true) && $this->due_on->endOfDay()->isPast(),
            'started_at' => $this->started_at?->toIso8601ZuluString('millisecond'),
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString('millisecond'),
            'worked_minutes' => $this->worked_minutes,
            'quantity' => $this->quantity === null ? null : (float) $this->quantity,
            'unit' => $this->unit,
            'submit_note' => $this->submit_note,
            'review_note' => $this->review_note,
            'verified_at' => $this->verified_at?->toIso8601ZuluString(),
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier ? ['id' => $this->verifier->id, 'name' => $this->verifier->name] : null),
            'worker' => $this->whenLoaded('worker', fn () => ['id' => $this->worker->id, 'worker_code' => $this->worker->worker_code, 'full_name' => $this->worker->full_name]),
            'activity' => $a ? [
                'id' => $a->id,
                'code' => $a->code,
                'title' => $a->title,
                'module' => $a->module,
                'activity_type' => $a->relationLoaded('type') ? ['code' => $a->type->code, 'name' => $a->type->name] : null,
                'instructions' => $a->instructions,
                'subject' => ['type' => $a->subject_type->value, 'id' => $a->subject_id, 'label' => $a->subject_label],
                'plot_id' => $a->plot_id,
                'location_id' => $a->location_id,
                'planned_on' => $a->planned_on->toDateString(),
                'priority' => $a->priority->value,
                'target_quantity' => $a->target_quantity === null ? null : (float) $a->target_quantity,
                'target_unit' => $a->target_unit,
                'status' => $a->status->value,
            ] : null,
            'logs' => $this->whenLoaded('logs', fn () => $this->logs->map(fn ($l) => [
                'id' => $l->id,
                'event' => $l->event->value,
                'from_status' => $l->from_status,
                'to_status' => $l->to_status,
                'applied' => $l->applied,
                'occurred_at' => $l->occurred_at->toIso8601ZuluString('millisecond'),
                'point' => $seesPlaces && $l->lat !== null ? ['lat' => $l->lat, 'lng' => $l->lng, 'accuracy_m' => $l->accuracy_m] : null,
                'quantity' => $l->quantity === null ? null : (float) $l->quantity,
                'unit' => $l->unit,
                'note' => $l->note,
                'recorded_by' => $l->relationLoaded('recorder') && $l->recorder ? ['id' => $l->recorder->id, 'name' => $l->recorder->name] : null,
            ])->values()),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($p) => [
                'id' => $p->id,
                'media_id' => $p->media_id,
                'taken_at' => $p->taken_at?->toIso8601ZuluString('millisecond'),
                'point' => $seesPlaces && $p->lat !== null ? ['lat' => $p->lat, 'lng' => $p->lng, 'accuracy_m' => $p->accuracy_m] : null,
                'caption' => $p->caption,
            ])->values()),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}
