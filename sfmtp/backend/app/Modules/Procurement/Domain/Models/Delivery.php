<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods received against a purchase order (a GRN). Never changed.
 */
class Delivery extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['farm_id', 'code', 'order_id', 'location_id', 'received_on', 'supplier_reference', 'media_id', 'note', 'received_by'];

    protected function casts(): array
    {
        return ['received_on' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'delivery_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
