<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Turns a recorded day into the moment a trace event happened.
 *
 * Field records carry a date, not a time, so events get a typical hour of the
 * day. For today that hour may still be ahead, and an event must never be
 * dated in the future, so the result is capped at now.
 */
final class EventTime
{
    public static function on(DateTimeInterface|string $day, int $hour): CarbonImmutable
    {
        $at = CarbonImmutable::parse($day)->setTime($hour, 0);
        $now = CarbonImmutable::now()->startOfSecond();

        return $at->greaterThan($now) ? $now : $at;
    }
}
