<?php

namespace App\Modules\Crops\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A crop the farm grows, usually picked from the global catalogue.
 *
 * @property string $id
 * @property string $name
 * @property string|null $variety
 * @property int|null $maturity_days
 * @property string $yield_unit
 */
class Crop extends Model
{
    use BelongsToFarm, HasUuids;

    protected $fillable = ['farm_id', 'global_crop_id', 'global_variety_id', 'name', 'variety', 'maturity_days', 'yield_unit', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'maturity_days' => 'integer'];
    }

    public function label(): string
    {
        return $this->variety ? "{$this->name} ({$this->variety})" : $this->name;
    }
}
