<?php

namespace App\Modules\Workforce\Http\Resources;

use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attendance */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $access = app(WorkforceAccess::class);
        $seesPlaces = $access->can('worker.gps.view') || $access->currentWorker()?->id === $this->worker_id;
        $point = fn (string $p) => $seesPlaces && $this->{"{$p}lat"} !== null
            ? ['lat' => $this->{"{$p}lat"}, 'lng' => $this->{"{$p}lng"}, 'accuracy_m' => $this->{"{$p}accuracy_m"}] : null;

        return [
            'id' => $this->id,
            'type' => 'attendance',
            'worker' => $this->whenLoaded('worker', fn () => ['id' => $this->worker->id, 'worker_code' => $this->worker->worker_code, 'full_name' => $this->worker->full_name]),
            'worker_id' => $this->worker_id,
            'work_date' => $this->work_date->toDateString(),
            'check_in_at' => $this->check_in_at->toIso8601ZuluString('millisecond'),
            'check_in_point' => $point('check_in_'),
            'check_in_photo_id' => $this->check_in_photo_id,
            'check_out_at' => $this->check_out_at?->toIso8601ZuluString('millisecond'),
            'check_out_point' => $point('check_out_'),
            'check_out_photo_id' => $this->check_out_photo_id,
            'minutes' => $this->minutes(),
            'source' => $this->source->value,
            'note' => $this->note,
            'version' => $this->version,
            'updated_at' => $this->updated_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}
