<?php

namespace App\Modules\FarmStructure\Application;

use App\Modules\FarmStructure\Domain\Models\Block;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\Integrations\Weather\WeatherService;
use App\Modules\Tenancy\TenantContext;

/**
 * The forecast at the farm (ADR-0018): the middle of its mapped blocks, else
 * its plots, else its pinned locations. A farm with nothing mapped has no
 * forecast yet.
 */
class FarmWeather
{
    public function __construct(private readonly TenantContext $context, private readonly WeatherService $weather) {}

    /** @return array{lat:float, lng:float}|null */
    public function center(): ?array
    {
        foreach ([Block::class, Plot::class] as $model) {
            $row = $model::whereNotNull('centroid_lat')->selectRaw('AVG(centroid_lat) AS lat, AVG(centroid_lng) AS lng')->first();
            if ($row && $row->lat !== null) {
                return ['lat' => (float) $row->lat, 'lng' => (float) $row->lng];
            }
        }
        $row = Location::whereNotNull('latitude')->selectRaw('AVG(latitude) AS lat, AVG(longitude) AS lng')->first();

        return $row && $row->lat !== null ? ['lat' => (float) $row->lat, 'lng' => (float) $row->lng] : null;
    }

    public function forecast(): array
    {
        $center = $this->center();
        if ($center === null) {
            return ['available' => false, 'reason' => 'no_location'];
        }

        return $this->weather->forecast($center['lat'], $center['lng'], $this->context->farm()->timezone);
    }
}
