<?php

namespace App\Modules\FarmStructure\Domain\Models;

use App\Modules\FarmStructure\Domain\Geometry;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared behaviour of blocks, sections, plots and locations: farm scope,
 * archiving (soft delete), optimistic version, and a GeoJSON boundary with
 * derived area, centroid and bounding box (ADR-0009).
 *
 * @property string $id
 * @property string $farm_id
 * @property string $code
 * @property string $name
 * @property array|null $boundary
 * @property string|null $area_ha
 * @property string|null $declared_area_ha
 * @property int $version
 */
abstract class StructureNode extends Model
{
    use BelongsToFarm, HasUuids, SoftDeletes;

    /** Short type name used in API payloads and warnings. */
    abstract public function nodeType(): string;

    protected function casts(): array
    {
        return [
            'boundary' => 'array',
            'area_ha' => 'decimal:4',
            'declared_area_ha' => 'decimal:4',
            'centroid_lat' => 'decimal:7',
            'centroid_lng' => 'decimal:7',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (self $node) => $node->version = $node->getOriginal('version') + 1);
    }

    /** Set (or clear) the boundary and recompute the derived columns. */
    public function setBoundary(?array $geometry): void
    {
        if ($geometry === null) {
            $this->forceFill(array_fill_keys(['boundary', 'area_ha', 'centroid_lat', 'centroid_lng', 'bbox_min_lat', 'bbox_min_lng', 'bbox_max_lat', 'bbox_max_lng'], null));

            return;
        }

        $polygon = Geometry::normalize($geometry);
        [$lng, $lat] = Geometry::centroid($polygon);
        $bbox = Geometry::bbox($polygon);

        $this->forceFill([
            'boundary' => $polygon,
            'area_ha' => Geometry::areaHa($polygon),
            'centroid_lat' => $lat,
            'centroid_lng' => $lng,
            'bbox_min_lat' => $bbox['min_lat'],
            'bbox_min_lng' => $bbox['min_lng'],
            'bbox_max_lat' => $bbox['max_lat'],
            'bbox_max_lng' => $bbox['max_lng'],
        ]);
    }

    /** Mapped area when a boundary exists, otherwise the declared area. */
    public function effectiveAreaHa(): ?float
    {
        $area = $this->area_ha ?? $this->declared_area_ha;

        return $area === null ? null : (float) $area;
    }
}
