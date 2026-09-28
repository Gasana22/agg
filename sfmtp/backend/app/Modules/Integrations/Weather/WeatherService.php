<?php

namespace App\Modules\Integrations\Weather;

use App\Modules\Integrations\Application\IntegrationUnavailable;
use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use App\Modules\Integrations\Application\Router;
use Illuminate\Support\Facades\Cache;

/**
 * The forecast for a place, from the first weather provider that answers
 * (ADR-0018). Cached for 30 minutes per ~1 km square, so a farm's
 * dashboards, phones and reports share one call. Adds simple advisories a
 * farmer can act on: heavy rain, a dry spell to spray in, heat stress.
 */
class WeatherService
{
    public const ADAPTERS = ['openweather' => OpenWeather::class, 'tomorrow_io' => TomorrowIo::class];

    public const CACHE_SECONDS = 1800;

    public function __construct(private readonly Router $router) {}

    /**
     * @return array{available:bool, provider?:string, location?:array, current?:array, daily?:array, advisories?:array, reason?:string, fetched_at?:string}
     */
    public function forecast(float $lat, float $lng, string $timezone, ?string $onlyProviderId = null): array
    {
        $key = sprintf('weather:%.2f:%.2f:%s:%s', $lat, $lng, $timezone, $onlyProviderId ?? '*');
        if (($hit = Cache::get($key)) !== null) {
            return $hit;
        }
        try {
            [$data, $provider] = $this->router->run('weather', function (ProviderConfig $c) use ($lat, $lng, $timezone) {
                $class = self::ADAPTERS[$c->provider] ?? throw new ProviderFailure("No weather adapter for {$c->provider}.");

                return app($class)->forecast($c, $lat, $lng, $timezone);
            }, $onlyProviderId);
        } catch (IntegrationUnavailable $e) {
            // Not cached: the next request tries again.
            return ['available' => false, 'reason' => $e->errors === [] ? 'not_configured' : 'unavailable'];
        }

        $result = ['available' => true, 'provider' => $provider->provider, 'location' => ['lat' => round($lat, 4), 'lng' => round($lng, 4)]]
            + $data + ['advisories' => self::advisories($data['daily']), 'fetched_at' => now()->toIso8601ZuluString()];
        Cache::put($key, $result, self::CACHE_SECONDS);

        return $result;
    }

    /** @return array<int, array{kind:string, severity:string, message:string, date?:string}> */
    public static function advisories(array $daily): array
    {
        $out = [];
        foreach (array_slice($daily, 0, 3) as $d) {
            if ($d['rain_mm'] >= 20 || $d['condition'] === 'storm') {
                $out[] = ['kind' => 'heavy_rain', 'severity' => 'warning', 'date' => $d['date'],
                    'message' => "Heavy rain expected on {$d['date']} ({$d['rain_mm']} mm): check drainage and avoid spraying."];
            }
            if ($d['max_c'] >= 32) {
                $out[] = ['kind' => 'heat', 'severity' => 'warning', 'date' => $d['date'],
                    'message' => "Hot on {$d['date']} ({$d['max_c']} °C): give animals shade and water, and irrigate early."];
            }
        }
        $dry = array_filter(array_slice($daily, 0, 3), fn ($d) => $d['rain_chance_pct'] < 30);
        if (count($dry) >= 2 && ! array_filter($out, fn ($a) => $a['kind'] === 'heavy_rain')) {
            $out[] = ['kind' => 'spray_window', 'severity' => 'info', 'message' => 'Mostly dry for the next days: a good window for spraying and harvest.'];
        }

        return $out;
    }
}
