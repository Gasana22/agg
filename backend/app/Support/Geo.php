<?php

namespace App\Support;

/**
 * Small stateless geo-math helpers. Deliberately not a PostGIS-backed
 * geospatial layer — the platform's coordinates are simple lat/lng
 * decimals, so the great-circle distance formula covers everything this
 * codebase currently needs (geofencing a check-in against a farm's
 * reference point).
 */
class Geo
{
    private const EARTH_RADIUS_METERS = 6371000;

    /**
     * Great-circle distance between two lat/lng points, in meters
     * (haversine formula).
     */
    public static function distanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }
}
