<?php

namespace App\Modules\FarmStructure\Domain\Models;

use App\Modules\FarmStructure\Domain\Enums\LocationKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place on the farm: a store, a building, a paddock, a water point. It has
 * a point, an area, or both; stores become inventory locations in Phase 7.
 *
 * @property LocationKind $kind
 * @property string|null $plot_id
 */
class Location extends StructureNode
{
    protected $table = 'farm_locations';

    protected $fillable = [
        'farm_id', 'plot_id', 'code', 'name', 'description', 'kind', 'latitude', 'longitude',
        'declared_area_ha', 'created_by',
    ];

    protected function casts(): array
    {
        return parent::casts() + [
            'kind' => LocationKind::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function nodeType(): string
    {
        return 'location';
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }
}
