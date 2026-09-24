<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The supplier's notice that goods are on the way (from the portal):
 * dispatched → received (by a GRN) or cancelled.
 */
class SupplierDispatch extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'order_id', 'status', 'dispatched_on', 'expected_on', 'reference', 'media_id', 'vehicle', 'driver', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['dispatched_on' => 'date', 'expected_on' => 'date', 'received_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierDispatchLine::class, 'dispatch_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'delivery_id');
    }

    /** @return array<string, mixed> */
    public function toPortal(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'dispatched_on' => $this->dispatched_on->toDateString(),
            'expected_on' => $this->expected_on?->toDateString(),
            'reference' => $this->reference,
            'has_document' => $this->media_id !== null,
            'vehicle' => $this->vehicle,
            'driver' => $this->driver,
            'note' => $this->note,
            'received_at' => $this->received_at?->toIso8601ZuluString(),
            'lines' => $this->lines->map(fn ($l) => ['order_line_id' => $l->order_line_id, 'quantity' => (float) $l->quantity])->values()->all(),
        ];
    }
}
