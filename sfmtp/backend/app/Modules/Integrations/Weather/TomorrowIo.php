<?php

namespace App\Modules\Integrations\Weather;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** Tomorrow.io Weather Forecast API v4. Setting: `api_key`. */
class TomorrowIo implements WeatherAdapter
{
    private const DESCRIPTIONS = ['clear' => 'Clear', 'partly_cloudy' => 'Partly cloudy', 'cloudy' => 'Cloudy', 'fog' => 'Fog', 'drizzle' => 'Drizzle',
        'rain' => 'Rain', 'storm' => 'Thunderstorm', 'snow' => 'Snow'];

    public function forecast(ProviderConfig $config, float $lat, float $lng, string $timezone): array
    {
        $res = Http::acceptJson()->timeout(6)->get('https://api.tomorrow.io/v4/weather/forecast', [
            'location' => "{$lat},{$lng}", 'timesteps' => '1h,1d', 'units' => 'metric', 'apikey' => $config->require('api_key'),
        ]);
        if (! $res->successful() || ! is_array($res->json('timelines.daily'))) {
            throw new ProviderFailure("Tomorrow.io answered HTTP {$res->status()}", retryable: true, status: $res->status());
        }
        $now = $res->json('timelines.hourly.0.values') ?? $res->json('timelines.daily.0.values');
        $condition = self::condition((int) ($now['weatherCode'] ?? $now['weatherCodeMax'] ?? 1000));

        return [
            'current' => [
                'temp_c' => round((float) ($now['temperature'] ?? $now['temperatureAvg'] ?? 0), 1),
                'humidity_pct' => (int) round((float) ($now['humidity'] ?? $now['humidityAvg'] ?? 0)),
                'wind_kmh' => round((float) ($now['windSpeed'] ?? $now['windSpeedAvg'] ?? 0) * 3.6, 1),
                'condition' => $condition,
                'description' => self::DESCRIPTIONS[$condition],
            ],
            'daily' => array_map(fn (array $d) => [
                'date' => CarbonImmutable::parse($d['time'])->setTimezone($timezone)->toDateString(),
                'min_c' => round((float) $d['values']['temperatureMin'], 1),
                'max_c' => round((float) $d['values']['temperatureMax'], 1),
                'rain_mm' => round((float) ($d['values']['rainAccumulationSum'] ?? 0), 1),
                'rain_chance_pct' => (int) round((float) ($d['values']['precipitationProbabilityAvg'] ?? 0)),
                'condition' => self::condition((int) ($d['values']['weatherCodeMax'] ?? 1000)),
            ], array_slice($res->json('timelines.daily'), 0, 7)),
        ];
    }

    /** Tomorrow.io weather codes. */
    private static function condition(int $code): string
    {
        return match (true) {
            $code === 1000 => 'clear',
            in_array($code, [1100, 1101], true) => 'partly_cloudy',
            in_array($code, [1001, 1102], true) => 'cloudy',
            $code >= 2000 && $code < 3000 => 'fog',
            $code === 4000 => 'drizzle',
            $code >= 4000 && $code < 5000 => 'rain',
            $code >= 5000 && $code < 8000 => 'snow',
            $code === 8000 => 'storm',
            default => 'cloudy',
        };
    }
}
