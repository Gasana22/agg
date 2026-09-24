<?php

namespace App\Modules\Livestock\Domain\Models;

use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Enums\GroupPurpose;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A herd, flock or pen. Flocks kept without individual records carry their
 * size in `flock_size`.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $species_id
 * @property GroupPurpose $purpose
 * @property string|null $location_id
 * @property int|null $flock_size
 */
class AnimalGroup extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'name', 'species_id', 'purpose', 'location_id', 'flock_size', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['purpose' => GroupPurpose::class, 'is_active' => 'boolean', 'flock_size' => 'integer', 'version' => 'integer'];
    }

    public function animals(): HasMany
    {
        return $this->hasMany(Animal::class, 'group_id');
    }

    public function activeAnimals(): HasMany
    {
        return $this->animals()->where('status', AnimalStatus::Active->value);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }
}
