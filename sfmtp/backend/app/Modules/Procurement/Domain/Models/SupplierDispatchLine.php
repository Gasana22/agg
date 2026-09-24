<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupplierDispatchLine extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public $timestamps = false;

    protected $fillable = ['farm_id', 'dispatch_id', 'order_line_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
