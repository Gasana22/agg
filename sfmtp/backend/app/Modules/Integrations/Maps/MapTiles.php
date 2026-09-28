<?php

namespace App\Modules\Integrations\Maps;

use App\Modules\Integrations\Application\IntegrationUnavailable;
use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use App\Modules\Integrations\Application\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Which base map the web and mobile maps draw (ADR-0018). The default maps
 * provider in the admin portal wins; with none, or when it fails,
 * OpenStreetMap. Only browser-safe values leave the server: Mapbox public
 * tokens (`pk.…`) and Google Map Tiles keys are meant to sit in page URLs
 * and must be restricted to the app's domains in the provider's console.
 */
class MapTiles
{
    public const OSM = [
        'provider' => 'osm',
        'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution' => '&copy; OpenStreetMap contributors',
        'max_zoom' => 19,
    ];

    public function __construct(private readonly Router $router) {}

    /** @return array{provider:string, tile_url:string, attribution:string, max_zoom:int} */
    public function config(?string $onlyProviderId = null): array
    {
        return Cache::remember('maps:config:'.($onlyProviderId ?? '*'), 600, function () use ($onlyProviderId) {
            try {
                return $this->router->run('maps', fn (ProviderConfig $c) => match ($c->provider) {
                    'mapbox' => $this->mapbox($c),
                    'google' => $this->google($c),
                    default => throw new ProviderFailure("No maps adapter for {$c->provider}."),
                }, $onlyProviderId)[0];
            } catch (IntegrationUnavailable) {
                return self::OSM;
            }
        });
    }

    private function mapbox(ProviderConfig $c): array
    {
        $token = $c->require('access_token');
        if (! str_starts_with($token, 'pk.')) {
            // A secret token must never reach a browser.
            throw new ProviderFailure('Mapbox: use a public token (pk.…) restricted to your domains.', retryable: true);
        }
        $style = $c->get('style') ?? 'mapbox/satellite-streets-v12';

        return ['provider' => 'mapbox', 'tile_url' => "https://api.mapbox.com/styles/v1/{$style}/tiles/256/{z}/{x}/{y}@2x?access_token={$token}",
            'attribution' => '&copy; Mapbox &copy; OpenStreetMap contributors', 'max_zoom' => 22];
    }

    /** Google Map Tiles API: a session (valid about two weeks) names the map type. */
    private function google(ProviderConfig $c): array
    {
        $key = $c->require('api_key');
        $type = $c->get('map_type') ?? 'satellite';
        $session = Cache::get("maps:google:session:{$c->id}:{$type}");
        if ($session === null) {
            $res = Http::acceptJson()->timeout(6)->post("https://tile.googleapis.com/v1/createSession?key={$key}",
                array_filter(['mapType' => $type, 'language' => 'en-US', 'region' => $c->get('region') ?? 'UG']));
            if (! $res->successful() || ! $res->json('session')) {
                throw new ProviderFailure("Google Map Tiles answered HTTP {$res->status()}", retryable: true, status: $res->status());
            }
            $session = $res->json('session');
            $ttl = max(60, (int) $res->json('expiry') - time() - 3600);
            Cache::put("maps:google:session:{$c->id}:{$type}", $session, $ttl);
        }

        return ['provider' => 'google', 'tile_url' => "https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}?session={$session}&key={$key}",
            'attribution' => 'Map data &copy; Google', 'max_zoom' => 22];
    }
}
