<?php

namespace App\Http\Controllers\Api\Maps;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Renders the farm and its blocks as a GeoJSON FeatureCollection, ready to
 * hand straight to a map library (Leaflet, Mapbox GL, ...) on the frontend.
 * Sections and plots are deliberately left out of this — boundary mapping
 * stops at block granularity for now; they're too fine-grained for a
 * first-pass map view.
 */
class FarmMapController extends Controller
{
    public function show(Request $request, Farm $farm): JsonResponse
    {
        abort_unless($request->user()->canViewFarm($farm), 403);

        $features = [];

        if ($farm->boundary) {
            $features[] = $this->polygonFeature($farm->boundary, [
                'kind' => 'farm_boundary',
                'id' => $farm->id,
                'name' => $farm->name,
            ]);
        } elseif ($farm->gps_lat !== null && $farm->gps_lng !== null) {
            $features[] = $this->pointFeature((float) $farm->gps_lat, (float) $farm->gps_lng, [
                'kind' => 'farm',
                'id' => $farm->id,
                'name' => $farm->name,
            ]);
        }

        foreach ($farm->blocks as $block) {
            if ($block->boundary) {
                $features[] = $this->polygonFeature($block->boundary, [
                    'kind' => 'block_boundary',
                    'id' => $block->id,
                    'name' => $block->name,
                ]);
            } elseif ($block->gps_lat !== null && $block->gps_lng !== null) {
                $features[] = $this->pointFeature((float) $block->gps_lat, (float) $block->gps_lng, [
                    'kind' => 'block',
                    'id' => $block->id,
                    'name' => $block->name,
                ]);
            }
        }

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function pointFeature(float $lat, float $lng, array $properties): array
    {
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$lng, $lat],
            ],
            'properties' => $properties,
        ];
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $boundary
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function polygonFeature(array $boundary, array $properties): array
    {
        $ring = array_map(fn (array $point) => [$point['lng'], $point['lat']], $boundary);

        if ($ring !== [] && $ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }

        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [$ring],
            ],
            'properties' => $properties,
        ];
    }
}
