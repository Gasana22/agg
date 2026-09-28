<?php

namespace App\Modules\Integrations\Weather;

use App\Modules\Integrations\Application\ProviderConfig;

/**
 * A forecast in one shape whatever the provider:
 * `current` {temp_c, humidity_pct, wind_kmh, condition, description} and
 * `daily` [{date, min_c, max_c, rain_mm, rain_chance_pct, condition}] for up
 * to seven days. Conditions: clear, partly_cloudy, cloudy, fog, drizzle,
 * rain, storm, snow.
 */
interface WeatherAdapter
{
    /** @return array{current: array, daily: array<int, array>} */
    public function forecast(ProviderConfig $config, float $lat, float $lng, string $timezone): array;
}
