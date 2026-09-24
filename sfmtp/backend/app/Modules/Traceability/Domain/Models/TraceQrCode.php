<?php

namespace App\Modules\Traceability\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public QR code for a batch. The code is random, not the batch id.
 *
 * @property string $status active | revoked
 */
class TraceQrCode extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'batch_id', 'code', 'status', 'approval_id', 'label', 'issued_by', 'issued_at'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'revoked_at' => 'datetime', 'last_scanned_at' => 'datetime', 'scan_count' => 'integer', 'version' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'batch_id');
    }

    public function url(): string
    {
        return rtrim((string) config('sfmtp.trace_url'), '/').'/'.$this->code;
    }
}
