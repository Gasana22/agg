<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to buy: submitted by a member, approved before a purchase order is raised.
 */
class PurchaseRequest extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'needed_by', 'reason', 'requested_by'];

    protected function casts(): array
    {
        return ['needed_by' => 'date', 'decided_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class, 'request_id');
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
