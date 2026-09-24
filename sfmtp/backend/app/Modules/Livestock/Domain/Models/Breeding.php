<?php

namespace App\Modules\Livestock\Domain\Models;

use App\Modules\Livestock\Domain\Enums\BreedingMethod;
use App\Modules\Livestock\Domain\Enums\BreedingStatus;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A service (natural or AI) and what came of it.
 *
 * @property string $id
 * @property string $dam_id
 * @property BreedingStatus $status
 * @property Carbon|null $expected_due_on
 */
class Breeding extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $table = 'animal_breedings';

    protected $fillable = ['farm_id', 'dam_id', 'sire_id', 'sire_note', 'method', 'served_on', 'expected_due_on', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'method' => BreedingMethod::class,
            'status' => BreedingStatus::class,
            'served_on' => 'date',
            'expected_due_on' => 'date',
            'outcome_on' => 'date',
            'offspring_count' => 'integer',
            'version' => 'integer',
        ];
    }

    public function dam(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }

    public function sire(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'sire_id');
    }
}
