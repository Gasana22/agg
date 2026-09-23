<?php

namespace Tests\Unit;

use App\Modules\FarmStructure\Domain\Geometry;
use PHPUnit\Framework\TestCase;

class GeometryTest extends TestCase
{
    private static function square(float $lng, float $lat, float $size, bool $clockwise = false): array
    {
        $ring = [[$lng, $lat], [$lng + $size, $lat], [$lng + $size, $lat + $size], [$lng, $lat + $size], [$lng, $lat]];

        return ['type' => 'Polygon', 'coordinates' => [$clockwise ? array_reverse($ring) : $ring]];
    }

    public function test_area_matches_the_spherical_reference(): void
    {
        // 1 arc-minute of longitude at the equator on the WGS 84 sphere is
        // 1855.325 m, so a 1'×1' cell is about 344.2 ha.
        $this->assertEqualsWithDelta(344.2, Geometry::areaHa(Geometry::normalize(self::square(32, 0, 1 / 60))), 0.5);

        // Area shrinks with the cosine of latitude.
        $equator = Geometry::areaM2(self::square(32, 0, 0.01));
        $north = Geometry::areaM2(self::square(32, 60, 0.01));
        $this->assertEqualsWithDelta(0.5, $north / $equator, 0.001);
    }

    public function test_holes_are_subtracted(): void
    {
        $outer = self::square(30, 0, 0.01)['coordinates'][0];
        $hole = self::square(30.0025, 0.0025, 0.005)['coordinates'][0];
        $withHole = Geometry::normalize(['type' => 'Polygon', 'coordinates' => [$outer, $hole]]);

        $this->assertEqualsWithDelta(0.75, Geometry::areaM2($withHole) / Geometry::areaM2(self::square(30, 0, 0.01)), 0.001);
    }

    public function test_normalize_orients_rings_and_rounds(): void
    {
        $polygon = Geometry::normalize(self::square(30.123456789, 0.1, 0.01, clockwise: true));

        $this->assertSame(30.1234568, $polygon['coordinates'][0][0][0]);
        // Counter-clockwise outer ring: the second vertex is east of the first.
        $this->assertGreaterThan($polygon['coordinates'][0][0][0], $polygon['coordinates'][0][1][0]);
    }

    public function test_centroid_and_bbox(): void
    {
        $polygon = Geometry::normalize(self::square(30, 1, 0.02));

        $this->assertSame([30.01, 1.01], Geometry::centroid($polygon));
        $this->assertSame(['min_lng' => 30.0, 'min_lat' => 1.0, 'max_lng' => 30.02, 'max_lat' => 1.02], Geometry::bbox($polygon));
    }

    public function test_validation(): void
    {
        $this->assertSame([], Geometry::errors(self::square(30, 0, 0.01)));
        $this->assertNotEmpty(Geometry::errors(['type' => 'Polygon', 'coordinates' => [[[30, 0], [30.01, 0.01], [30.01, 0], [30, 0.01], [30, 0]]]]));
        $this->assertNotEmpty(Geometry::errors(['type' => 'Polygon', 'coordinates' => [[[30, 0], [30.01, 0], [30, 0]]]]));
        $this->assertNotEmpty(Geometry::errors(['type' => 'Polygon', 'coordinates' => [[[30, 0], [30.01, 0], [30.02, 0], [30, 0]]]]));   // no area

        $ring = [];
        for ($i = 0; $i < 1001; $i++) {
            $a = 2 * M_PI * $i / 1001;
            $ring[] = [30 + 0.01 * cos($a), 0.01 * sin($a)];
        }
        $ring[] = $ring[0];
        $this->assertStringContainsString('1000 vertices', Geometry::errors(['type' => 'Polygon', 'coordinates' => [$ring]])[0]);
    }

    public function test_within_and_overlaps(): void
    {
        $block = self::square(30, 0, 0.01);

        $this->assertTrue(Geometry::within(self::square(30.001, 0.001, 0.002), $block));
        $this->assertTrue(Geometry::within($block, $block));
        $this->assertTrue(Geometry::within(self::square(29.999995, 0, 0.005), $block));   // GPS tolerance
        $this->assertFalse(Geometry::within(self::square(30.009, 0.009, 0.002), $block));

        $this->assertTrue(Geometry::overlaps($block, self::square(30.005, 0.005, 0.01)));
        $this->assertTrue(Geometry::overlaps($block, $block));
        $this->assertTrue(Geometry::overlaps($block, self::square(30.002, 0.002, 0.001)));   // fully inside
        $this->assertFalse(Geometry::overlaps($block, self::square(30.01, 0, 0.01)));        // shared edge
        $this->assertFalse(Geometry::overlaps($block, self::square(31, 0, 0.01)));
    }
}
