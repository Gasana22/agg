<?php

namespace Tests\Unit;

use App\Modules\Traceability\Application\EventHasher;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The canonical form must not depend on how a database returns values,
 * or chains written on one engine would not verify on another (docs/07 §3).
 */
class EventHasherTest extends TestCase
{
    private function event(array $overrides = []): array
    {
        return $overrides + [
            'id' => '0192f0c0-0000-7000-8000-000000000001',
            'farm_id' => '0192f0c0-0000-7000-8000-0000000000aa',
            'farm_seq' => 7,
            'batch_id' => '0192f0c0-0000-7000-8000-0000000000bb',
            'event_type' => 'inspection',
            'occurred_at' => CarbonImmutable::parse('2026-09-23T07:02:11.123456Z'),
            'latitude' => '0.3736123',
            'longitude' => '32.7123000',
            'gps_accuracy_m' => '6.50',
            'payload' => ['b' => 1, 'a' => ['y' => 2.5, 'x' => [3, 1]]],
        ];
    }

    public function test_key_order_and_value_formats_do_not_change_the_hash(): void
    {
        $asWritten = EventHasher::hash(EventHasher::GENESIS, $this->event());

        // As MySQL / PostgreSQL might hand it back: JSON text with other key order,
        // strings for numbers, a timestamp string without the "T".
        $asRead = EventHasher::hash(EventHasher::GENESIS, $this->event([
            'farm_seq' => '7',
            'occurred_at' => '2026-09-23 07:02:11.123456',
            'latitude' => 0.3736123,
            'longitude' => '32.7123',
            'gps_accuracy_m' => 6.5,
            'payload' => '{"a": {"x": [3, 1], "y": 2.5}, "b": 1}',
        ]));

        $this->assertSame($asWritten, $asRead);
    }

    public function test_any_change_or_different_predecessor_changes_the_hash(): void
    {
        $base = EventHasher::hash(EventHasher::GENESIS, $this->event());

        $this->assertNotSame($base, EventHasher::hash(EventHasher::GENESIS, $this->event(['payload' => ['b' => 2, 'a' => ['y' => 2.5, 'x' => [3, 1]]]])));
        $this->assertNotSame($base, EventHasher::hash(EventHasher::GENESIS, $this->event(['payload' => ['b' => 1, 'a' => ['y' => 2.5, 'x' => [1, 3]]]])), 'list order is data');
        $this->assertNotSame($base, EventHasher::hash(str_repeat('f', 64), $this->event()));
        $this->assertSame(64, strlen($base));
    }
}
