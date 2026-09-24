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
 * A request for stock for a piece of work: approved, then issued by the store.
 *
 * @property string $id
 * @property string $code
 * @property string $status requested | approved | rejected | partially_issued | issued | cancelled
 * @property string $subject_type
 * @property string|null $subject_id
 * @property string|null $requested_by
 */
class InventoryRequest extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'requested_by', 'task_id', 'subject_type', 'subject_id', 'subject_label', 'location_id', 'needed_on', 'note'];

    protected function casts(): array
    {
        return ['needed_on' => 'date', 'decided_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryRequestLine::class, 'request_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
