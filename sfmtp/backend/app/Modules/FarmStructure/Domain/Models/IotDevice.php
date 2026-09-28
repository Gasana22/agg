<?php

namespace App\Modules\FarmStructure\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sensor that posts readings with its own token (ADR-0018).
 *
 * @property string $id
 * @property string $farm_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string|null $plot_id
 * @property string|null $location_id
 * @property string $token_hint
 * @property bool $is_active
 * @property Carbon|null $last_seen_at
 */
class IotDevice extends Model
{
    use BelongsToFarm, HasUuids;

    public const KINDS = ['weather_station', 'soil_probe', 'water_meter', 'tank_level', 'cold_room', 'other'];

    protected $fillable = ['farm_id', 'code', 'name', 'kind', 'plot_id', 'location_id', 'is_active', 'created_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
