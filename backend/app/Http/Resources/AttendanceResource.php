<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'worker_profile_id' => $this->worker_profile_id,
            'worker' => $this->whenLoaded('workerProfile', fn () => [
                'id' => $this->workerProfile->id,
                'user_id' => $this->workerProfile->user_id,
                'name' => $this->workerProfile->user?->name,
            ]),
            'date' => $this->date,
            'check_in_at' => $this->check_in_at,
            'check_in_lat' => $this->check_in_lat,
            'check_in_lng' => $this->check_in_lng,
            'check_in_photo_url' => $this->check_in_photo_path
                ? Storage::disk('public')->url($this->check_in_photo_path)
                : null,
            'check_out_at' => $this->check_out_at,
            'check_out_lat' => $this->check_out_lat,
            'check_out_lng' => $this->check_out_lng,
            'check_out_photo_url' => $this->check_out_photo_path
                ? Storage::disk('public')->url($this->check_out_photo_path)
                : null,
            'status' => $this->status,
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'approved_at' => $this->approved_at,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
