<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock count that differs from the books, proposed and then approved.
 *
 * @property string $id
 * @property string $code
 * @property string $status proposed | approved | rejected | cancelled
 * @property string|null $proposed_by
 */
class StockAdjustment extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'location_id', 'reason', 'proposed_by'];

    protected function casts(): array
    {
        return ['value_change' => 'decimal:2', 'decided_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'adjustment_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
