<?php

namespace App\Modules\Traceability\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TraceBatchLink extends Model
{
    use BelongsToFarm, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['farm_id', 'parent_batch_id', 'child_batch_id', 'link_type', 'quantity', 'unit', 'created_by'];

    protected function casts(): array
    {
        return [
            'link_type' => LinkType::class,
            'quantity' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new AppendOnlyViolation);
        static::deleting(fn () => throw new AppendOnlyViolation);
    }
}
