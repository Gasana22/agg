<?php

namespace App\Modules\Workforce\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A location recorded by a worker's phone during a work session only
 * (checked in, or a task in progress: docs/08 §5).
 */
class GpsPoint extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public const UPDATED_AT = null;

    protected $table = 'worker_gps_points';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['id', 'farm_id', 'worker_id', 'task_id', 'recorded_at', 'lat', 'lng', 'accuracy_m', 'device_id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'lat' => 'float', 'lng' => 'float', 'accuracy_m' => 'float'];
    }
}
