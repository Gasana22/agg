<?php

namespace Tests\Unit;

use App\Modules\Reporting\Application\Period;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
    }

    public function test_periods_follow_the_farm_timezone(): void
    {
        CarbonImmutable::setTestNow('2026-09-23 22:30:00 UTC');   // already 24 Sept in Kampala (UTC+3)

        $today = Period::resolve('today', 'Africa/Kampala');

        $this->assertSame('2026-09-23T21:00:00+00:00', $today->from->toIso8601String());
        $this->assertSame(['key' => 'today', 'from' => '2026-09-24', 'to' => '2026-09-24'], $today->toArray('Africa/Kampala'));
    }

    public function test_previous_period_has_the_same_length_and_ends_just_before(): void
    {
        CarbonImmutable::setTestNow('2026-09-23 12:00:00 UTC');
        $week = Period::resolve('7d', 'UTC');
        $previous = $week->previous();

        $this->assertSame('2026-09-10', $previous->from->toDateString());
        $this->assertSame('2026-09-16 23:59:59.999999', $previous->to->format('Y-m-d H:i:s.u'));
    }
}
