<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm_id' => $this->farm_id,
            'worker_profile' => $this->whenLoaded('workerProfile', fn () => [
                'id' => $this->workerProfile->id,
                'user_id' => $this->workerProfile->user_id,
                'name' => $this->workerProfile->user?->name,
            ]),
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'days_worked' => $this->days_worked,
            'gross_amount' => $this->gross_amount,
            'deductions' => $this->deductions,
            'net_amount' => $this->net_amount,
            'status' => $this->status,
            'paid_date' => $this->paid_date,
            'recorder' => $this->whenLoaded('recorder', fn () => [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
