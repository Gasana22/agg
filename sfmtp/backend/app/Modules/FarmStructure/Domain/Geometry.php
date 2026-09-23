<?php

namespace App\Modules\FarmStructure\Domain;

/**
 * GeoJSON polygon checks and measurements (ADR-0009).
 *
 * Boundaries are stored as GeoJSON so both database engines can hold them.
 * Areas use the spherical-excess formula on the WGS 84 semi-major axis, which
 * is within a fraction of a percent of a geodesic area at farm scale.
 * Containment and overlap are planar tests in degrees: good enough for the
 * warnings they drive, which is all they are used for (docs/03 §3).
 */
final class Geometry
{
    public const EARTH_RADIUS_M = 6378137.0;

    public const MAX_VERTICES = 1000;

    public const MAX_HOLES = 20;

    /** About 1 m: GPS noise along a shared edge is not an overlap. */
    public const TOLERANCE_DEG = 0.00001;

    /**
     * Validate a GeoJSON Polygon. Returns a list of error messages (empty = valid).
     *
     * @return array<int,string>
     */
    public static function errors(mixed $geometry): array
    {
        if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'Polygon') {
            return ['The boundary must be a GeoJSON Polygon.'];
        }
        $rings = $geometry['coordinates'] ?? null;
        if (! is_array($rings) || $rings === [] || ! array_is_list($rings)) {
            return ['The boundary must have at least one ring of coordinates.'];
        }
        if (count($rings) > self::MAX_HOLES + 1) {
            return ['The boundary may have at most '.self::MAX_HOLES.' holes.'];
        }

        $vertices = 0;
        foreach ($rings as $i => $ring) {
            $name = $i === 0 ? 'The outer ring' : "Hole {$i}";
            if (! is_array($ring) || ! array_is_list($ring) || count($ring) < 4) {
                return ["{$name} needs at least 4 positions (3 corners and the closing point)."];
            }
            foreach ($ring as $position) {
                if (! is_array($position) || count($position) < 2 || ! is_numeric($position[0] ?? null) || ! is_numeric($position[1] ?? null)) {
                    return ["{$name} contains a position that is not [longitude, latitude]."];
                }
                if (abs((float) $position[0]) > 180 || abs((float) $position[1]) > 90) {
                    return ["{$name} has a position outside valid longitude / latitude ranges."];
                }
            }
            if ((float) $ring[0][0] !== (float) end($ring)[0] || (float) $ring[0][1] !== (float) end($ring)[1]) {
                return ["{$name} is not closed: the last position must equal the first."];
            }
            $vertices += count($ring) - 1;
        }

        if ($vertices > self::MAX_VERTICES) {
            return ['The boundary has more than '.self::MAX_VERTICES.' vertices. Simplify it before saving.'];
        }

        $errors = [];
        foreach (self::normalize($geometry)['coordinates'] as $i => $ring) {
            if (self::selfIntersects($ring)) {
                $errors[] = ($i === 0 ? 'The outer ring' : "Hole {$i}").' crosses itself.';
            }
        }
        if ($errors === [] && self::areaM2(self::normalize($geometry)) < 1.0) {
            $errors[] = 'The boundary has no area.';
        }

        return $errors;
    }

    /**
     * Canonical form: floats rounded to 7 decimals (about 1 cm), extra
     * dimensions (altitude) dropped, outer ring counter-clockwise and holes
     * clockwise (RFC 7946 §3.1.6).
     *
     * @return array{type:string, coordinates:array<int,array<int,array{0:float,1:float}>>}
     */
    public static function normalize(array $geometry): array
    {
        $rings = [];
        foreach ($geometry['coordinates'] as $i => $ring) {
            $ring = array_map(fn ($p) => [round((float) $p[0], 7), round((float) $p[1], 7)], $ring);
            $ccw = self::signedPlanarArea($ring) > 0;
            if (($i === 0) !== $ccw) {
                $ring = array_reverse($ring);
            }
            $rings[] = $ring;
        }

        return ['type' => 'Polygon', 'coordinates' => $rings];
    }

    /** Area in square metres: the outer ring minus its holes. */
    public static function areaM2(array $polygon): float
    {
        $area = 0.0;
        foreach ($polygon['coordinates'] as $i => $ring) {
            $a = abs(self::ringArea($ring));
            $area += $i === 0 ? $a : -$a;
        }

        return max(0.0, $area);
    }

    public static function areaHa(array $polygon): float
    {
        return round(self::areaM2($polygon) / 10000, 4);
    }

    /**
     * Area-weighted centroid of the outer ring, as [lng, lat].
     *
     * @return array{0:float,1:float}
     */
    public static function centroid(array $polygon): array
    {
        $ring = $polygon['coordinates'][0];
        $a = self::signedPlanarArea($ring);

        if (abs($a) < 1e-14) {
            $points = array_slice($ring, 0, -1);

            return [round(array_sum(array_column($points, 0)) / count($points), 7), round(array_sum(array_column($points, 1)) / count($points), 7)];
        }

        // Shift to the first vertex to keep the arithmetic well-conditioned.
        [$ox, $oy] = $ring[0];
        $cx = $cy = 0.0;
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            [$x1, $y1] = [$ring[$i][0] - $ox, $ring[$i][1] - $oy];
            [$x2, $y2] = [$ring[$i + 1][0] - $ox, $ring[$i + 1][1] - $oy];
            $f = $x1 * $y2 - $x2 * $y1;
            $cx += ($x1 + $x2) * $f;
            $cy += ($y1 + $y2) * $f;
        }

        return [round($cx / (6 * $a) + $ox, 7), round($cy / (6 * $a) + $oy, 7)];
    }

    /** @return array{min_lng:float, min_lat:float, max_lng:float, max_lat:float} */
    public static function bbox(array $polygon): array
    {
        $ring = $polygon['coordinates'][0];
        $lngs = array_column($ring, 0);
        $lats = array_column($ring, 1);

        return ['min_lng' => min($lngs), 'min_lat' => min($lats), 'max_lng' => max($lngs), 'max_lat' => max($lats)];
    }

    /** Whether $inner lies inside $outer, allowing GPS tolerance at the edge. */
    public static function within(array $inner, array $outer): bool
    {
        foreach (array_slice($inner['coordinates'][0], 0, -1) as $point) {
            if (! self::pointInRing($point, $outer['coordinates'][0]) && self::distanceToRing($point, $outer['coordinates'][0]) > self::TOLERANCE_DEG) {
                return false;
            }
        }

        return true;
    }

    /** Whether the two polygons share interior area (touching edges do not count). */
    public static function overlaps(array $a, array $b): bool
    {
        $ba = self::bbox($a);
        $bb = self::bbox($b);
        if ($ba['max_lng'] <= $bb['min_lng'] || $bb['max_lng'] <= $ba['min_lng'] || $ba['max_lat'] <= $bb['min_lat'] || $bb['max_lat'] <= $ba['min_lat']) {
            return false;
        }

        $ra = $a['coordinates'][0];
        $rb = $b['coordinates'][0];

        for ($i = 0, $n = count($ra) - 1; $i < $n; $i++) {
            for ($j = 0, $m = count($rb) - 1; $j < $m; $j++) {
                if (self::properlyCross($ra[$i], $ra[$i + 1], $rb[$j], $rb[$j + 1])) {
                    return true;
                }
            }
        }

        return self::hasPointClearlyInside($ra, $rb) || self::hasPointClearlyInside($rb, $ra);
    }

    public static function pointInRing(array $point, array $ring): bool
    {
        [$x, $y] = $point;
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** Signed area of a ring on the sphere, in m² (Chamberlain & Duquette). */
    private static function ringArea(array $ring): float
    {
        $n = count($ring);
        if ($n < 3) {
            return 0.0;
        }

        $total = 0.0;
        for ($i = 0; $i < $n; $i++) {
            [$lower, $middle, $upper] = match (true) {
                $i === $n - 2 => [$n - 2, $n - 1, 0],
                $i === $n - 1 => [$n - 1, 0, 1],
                default => [$i, $i + 1, $i + 2],
            };
            $total += (deg2rad($ring[$upper][0]) - deg2rad($ring[$lower][0])) * sin(deg2rad($ring[$middle][1]));
        }

        return $total * self::EARTH_RADIUS_M * self::EARTH_RADIUS_M / 2;
    }

    /** Shoelace area in square degrees; positive when counter-clockwise. */
    private static function signedPlanarArea(array $ring): float
    {
        [$ox, $oy] = $ring[0];
        $sum = 0.0;
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            $sum += ($ring[$i][0] - $ox) * ($ring[$i + 1][1] - $oy) - ($ring[$i + 1][0] - $ox) * ($ring[$i][1] - $oy);
        }

        return $sum / 2;
    }

    private static function selfIntersects(array $ring): bool
    {
        $n = count($ring) - 1;   // edges
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) {
                    continue;    // first and last edges share the closing vertex
                }
                if (self::segmentsIntersect($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function orientation(array $p, array $q, array $r): float
    {
        return ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);
    }

    /** Segments cross at a single interior point of both. */
    private static function properlyCross(array $a, array $b, array $c, array $d): bool
    {
        $o1 = self::orientation($a, $b, $c);
        $o2 = self::orientation($a, $b, $d);
        $o3 = self::orientation($c, $d, $a);
        $o4 = self::orientation($c, $d, $b);

        return (($o1 > 0 && $o2 < 0) || ($o1 < 0 && $o2 > 0)) && (($o3 > 0 && $o4 < 0) || ($o3 < 0 && $o4 > 0));
    }

    /** Segments share any point, including touching and collinear overlap. */
    private static function segmentsIntersect(array $a, array $b, array $c, array $d): bool
    {
        if (self::properlyCross($a, $b, $c, $d)) {
            return true;
        }
        $onSegment = fn (array $p, array $q, array $r) => self::orientation($p, $q, $r) == 0
            && min($p[0], $q[0]) <= $r[0] && $r[0] <= max($p[0], $q[0])
            && min($p[1], $q[1]) <= $r[1] && $r[1] <= max($p[1], $q[1]);

        return $onSegment($a, $b, $c) || $onSegment($a, $b, $d) || $onSegment($c, $d, $a) || $onSegment($c, $d, $b);
    }

    private static function hasPointClearlyInside(array $ring, array $container): bool
    {
        $points = array_slice($ring, 0, -1);

        // Vertices, then edge midpoints: two identical squares share no
        // strictly-inside vertex but their midpoints and centre are shared.
        for ($i = 0, $n = count($points); $i < $n; $i++) {
            $points[] = [($ring[$i][0] + $ring[$i + 1][0]) / 2, ($ring[$i][1] + $ring[$i + 1][1]) / 2];
        }
        $points[] = self::centroid(['coordinates' => [$ring]]);

        foreach ($points as $p) {
            if (self::pointInRing($p, $container) && self::distanceToRing($p, $container) > self::TOLERANCE_DEG) {
                return true;
            }
        }

        return false;
    }

    private static function distanceToRing(array $p, array $ring): float
    {
        $best = INF;
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            $best = min($best, self::distanceToSegment($p, $ring[$i], $ring[$i + 1]));
        }

        return $best;
    }

    private static function distanceToSegment(array $p, array $a, array $b): float
    {
        [$dx, $dy] = [$b[0] - $a[0], $b[1] - $a[1]];
        $len2 = $dx * $dx + $dy * $dy;
        $t = $len2 == 0.0 ? 0.0 : max(0.0, min(1.0, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $len2));

        return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
    }
}
