<?php

namespace App\Modules\FarmStructure\Domain\Models;

use App\Modules\FarmStructure\Domain\Enums\Irrigation;
use App\Modules\FarmStructure\Domain\Enums\LandUse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The smallest managed piece of land: crop cycles, grazing and trace events
 * refer to it.
 *
 * @property string|null $section_id
 * @property LandUse $land_use
 * @property Irrigation $irrigation
 * @property array|null $soil_profile
 */
class Plot extends StructureNode
{
    protected $table = 'farm_plots';

    protected $fillable = [
        'farm_id', 'section_id', 'code', 'name', 'description', 'declared_area_ha',
        'land_use', 'irrigation', 'created_by',
    ];

    protected function casts(): array
    {
        return parent::casts() + [
            'land_use' => LandUse::class,
            'irrigation' => Irrigation::class,
            'soil_profile' => 'array',
            'soil_updated_at' => 'datetime',
        ];
    }

    public function nodeType(): string
    {
        return 'plot';
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
