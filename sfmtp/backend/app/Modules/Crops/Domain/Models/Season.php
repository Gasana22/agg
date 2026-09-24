<?php

namespace App\Modules\Crops\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 */
class Season extends Model
{
    use BelongsToFarm, HasUuids;

    protected $table = 'crop_seasons';

    protected $fillable = ['farm_id', 'name', 'starts_on', 'ends_on', 'notes'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
