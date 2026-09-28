<?php

namespace App\Modules\Integrations\Weather;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** OpenWeather One Call API 3.0. Setting: `api_key`. */
class OpenWeather implements WeatherAdapter
{
    public function forecast(ProviderConfig $config, float $lat, float $lng, string $timezone): array
    {
        $res = Http::acceptJson()->timeout(6)->get('https://api.openweathermap.org/data/3.0/onecall', [
            'lat' => $lat, 'lon' => $lng, 'exclude' => 'minutely,hourly,alerts', 'units' => 'metric', 'appid' => $config->require('api_key'),
        ]);
        if (! $res->successful() || ! is_array($res->json('daily'))) {
            throw new ProviderFailure("OpenWeather answered HTTP {$res->status()}", retryable: true, status: $res->status());
        }
        $c = $res->json('current');

        return [
            'current' => [
                'temp_c' => round((float) $c['temp'], 1),
                'humidity_pct' => (int) $c['humidity'],
                'wind_kmh' => round((float) $c['wind_speed'] * 3.6, 1),
                'condition' => self::condition((int) ($c['weather'][0]['id'] ?? 800)),
                'description' => ucfirst((string) ($c['weather'][0]['description'] ?? '')),
            ],
            'daily' => array_map(fn (array $d) => [
                'date' => CarbonImmutable::createFromTimestamp((int) $d['dt'], $timezone)->toDateString(),
                'min_c' => round((float) $d['temp']['min'], 1),
                'max_c' => round((float) $d['temp']['max'], 1),
                'rain_mm' => round((float) ($d['rain'] ?? 0), 1),
                'rain_chance_pct' => (int) round((float) ($d['pop'] ?? 0) * 100),
                'condition' => self::condition((int) ($d['weather'][0]['id'] ?? 800)),
            ], array_slice($res->json('daily'), 0, 7)),
        ];
    }

    /** OpenWeather condition ids: 2xx storm, 3xx drizzle, 5xx rain, 6xx snow, 7xx fog, 800 clear, 801-802 partly, 803-804 cloudy. */
    private static function condition(int $id): string
    {
        return match (true) {
            $id >= 200 && $id < 300 => 'storm',
            $id >= 300 && $id < 400 => 'drizzle',
            $id >= 500 && $id < 600 => 'rain',
            $id >= 600 && $id < 700 => 'snow',
            $id >= 700 && $id < 800 => 'fog',
            $id === 800 => 'clear',
            $id <= 802 => 'partly_cloudy',
            default => 'cloudy',
        };
    }
}
