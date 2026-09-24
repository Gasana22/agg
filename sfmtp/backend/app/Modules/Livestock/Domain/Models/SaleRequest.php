<?php

namespace App\Modules\Livestock\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Livestock\Domain\Enums\SaleStatus;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to sell an animal: requested by the manager or livestock
 * manager, approved and completed by the owner (docs/04 §3 "Livestock sales").
 *
 * @property string $id
 * @property string $code
 * @property string $animal_id
 * @property SaleStatus $status
 * @property string|null $requested_by
 */
class SaleRequest extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $table = 'animal_sale_requests';

    protected $fillable = ['farm_id', 'code', 'animal_id', 'reason', 'buyer', 'expected_price', 'requested_by'];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'expected_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'decided_at' => 'datetime',
            'sold_on' => 'date',
            'version' => 'integer',
        ];
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
