<?php

namespace App\Modules\Traceability\Http\Resources;

use App\Modules\Traceability\Domain\Models\TraceQrCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TraceQrCode */
class TraceQrCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'trace_qr_code',
            'code' => $this->code,
            'status' => $this->status,
            'url' => $this->url(),
            'label' => $this->label,
            'batch' => $this->whenLoaded('batch', fn () => ['id' => $this->batch->id, 'batch_code' => $this->batch->batch_code, 'kind' => $this->batch->kind->value,
                'name' => $this->batch->name, 'status' => $this->batch->status->value]),
            'approval_id' => $this->approval_id,
            'issued_by' => $this->issued_by,
            'issued_at' => $this->issued_at?->toIso8601ZuluString(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'revoke_reason' => $this->revoke_reason,
            'scan_count' => (int) $this->scan_count,
            'last_scanned_at' => $this->last_scanned_at?->toIso8601ZuluString(),
            'version' => $this->version,
        ];
    }
}
