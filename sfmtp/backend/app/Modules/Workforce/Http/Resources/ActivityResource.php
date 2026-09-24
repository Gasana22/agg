<?php

namespace App\Modules\Workforce\Http\Resources;

use App\Modules\Workforce\Domain\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'activity',
            'code' => $this->code,
            'activity_type' => $this->whenLoaded('type', fn () => ['id' => $this->type->id, 'code' => $this->type->code, 'name' => $this->type->name]),
            'module' => $this->module,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'subject' => ['type' => $this->subject_type->value, 'id' => $this->subject_id, 'label' => $this->subject_label],
            'plot' => $this->whenLoaded('plot', fn () => $this->plot ? ['id' => $this->plot->id, 'code' => $this->plot->code, 'name' => $this->plot->name] : null),
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['id' => $this->location->id, 'code' => $this->location->code, 'name' => $this->location->name] : null),
            'planned_on' => $this->planned_on->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'priority' => $this->priority->value,
            'target_quantity' => $this->target_quantity === null ? null : (float) $this->target_quantity,
            'target_unit' => $this->target_unit,
            'status' => $this->status->value,
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'task_counts' => $this->when(isset($this->tasks_total), fn () => [
                'total' => (int) $this->tasks_total,
                'open' => (int) $this->tasks_open,
                'submitted' => (int) $this->tasks_submitted,
                'verified' => (int) $this->tasks_verified,
            ]),
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
