<?php

namespace App\Modules\Workforce\Http\Resources;

use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Worker */
class WorkerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $access = app(WorkforceAccess::class);

        return [
            'id' => $this->id,
            'type' => 'worker',
            'worker_code' => $this->worker_code,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'national_id' => $this->when($access->can('workers.manage'), $this->national_id),
            'job_title' => $this->job_title,
            'employment_type' => $this->employment_type->value,
            'daily_rate' => $this->when($access->seesMoney(), fn () => $this->daily_rate === null ? null : (float) $this->daily_rate),
            'started_on' => $this->started_on?->toDateString(),
            'left_on' => $this->left_on?->toDateString(),
            'status' => $this->status->value,
            'member' => $this->whenLoaded('membership', fn () => $this->membership ? [
                'id' => $this->membership->id,
                'user' => $this->membership->relationLoaded('user') && $this->membership->user
                    ? ['id' => $this->membership->user->id, 'name' => $this->membership->user->name, 'email' => $this->membership->user->email] : null,
            ] : null),
            'notes' => $this->when($access->can('workers.manage'), $this->notes),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
