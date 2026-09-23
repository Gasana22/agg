<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use Database\Factories\FarmFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant. Every farm-owned row carries this farm's id.
 *
 * @property string $id
 * @property FarmStatus $status
 */
#[UseFactory(FarmFactory::class)]
class Farm extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id', 'code', 'name', 'status', 'district', 'village',
        'country', 'size_ha', 'timezone', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'status' => FarmStatus::class,
            'size_ha' => 'decimal:4',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(FarmUser::class);
    }
}
