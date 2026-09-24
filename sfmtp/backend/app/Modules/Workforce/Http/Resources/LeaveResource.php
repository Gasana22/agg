<?php

namespace App\Modules\Workforce\Http\Resources;

use App\Modules\Workforce\Domain\Models\Leave;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Leave */
class LeaveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'leave',
            'worker' => $this->whenLoaded('worker', fn () => ['id' => $this->worker->id, 'worker_code' => $this->worker->worker_code, 'full_name' => $this->worker->full_name]),
            'worker_id' => $this->worker_id,
            'kind' => $this->kind->value,
            'from_on' => $this->from_on->toDateString(),
            'to_on' => $this->to_on->toDateString(),
            'days' => $this->days(),
            'reason' => $this->reason,
            'status' => $this->status->value,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name] : null),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}
