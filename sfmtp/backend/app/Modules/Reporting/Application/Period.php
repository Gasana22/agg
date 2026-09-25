<?php

namespace App\Modules\Reporting\Application;

use Carbon\CarbonImmutable;

/**
 * Dashboard reporting period, resolved in the farm's timezone and expressed
 * in UTC for queries. `season` arrives with crop seasons in Phase 4.
 */
final class Period
{
    public const KEYS = ['today', '7d', '30d', '90d', 'ytd', 'custom'];

    private function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function resolve(string $key, string $timezone, ?string $from = null, ?string $to = null): self
    {
        $now = CarbonImmutable::now($timezone);

        [$start, $end] = match ($key) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            '90d' => [$now->subDays(89)->startOfDay(), $now->endOfDay()],
            'ytd' => [$now->startOfYear(), $now->endOfDay()],
            'custom' => [CarbonImmutable::parse($from, $timezone)->startOfDay(), CarbonImmutable::parse($to, $timezone)->endOfDay()],
            default => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
        };

        return new self($key, $start->utc(), $end->utc());
    }

    /** A window between two UTC instants (series buckets, report ranges). */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, string $key = 'custom'): self
    {
        return new self($key, $from->utc(), $to->utc());
    }

    /**
     * The period cut into consecutive local days, weeks or months, whichever
     * keeps the series at 31 points or fewer.
     *
     * @return array<int, array{label:string, period:self}>
     */
    public function buckets(string $timezone): array
    {
        $from = $this->from->setTimezone($timezone);
        $to = $this->to->setTimezone($timezone);
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        $unit = $days <= 31 ? 'day' : ($days <= 31 * 7 ? 'week' : 'month');

        $out = [];
        for ($start = $from; $start <= $to;) {
            $end = match ($unit) {
                'day' => $start->endOfDay(),
                'week' => $start->addDays(6)->endOfDay(),
                'month' => $start->endOfMonth(),
            };
            $end = $end > $to ? $to : $end;
            $out[] = ['label' => $start->toDateString(), 'period' => new self($this->key, $start->utc(), $end->utc())];
            $start = $end->addMicrosecond()->startOfDay();
        }

        return $out;
    }

    /** The equal-length period immediately before this one (for deltas). */
    public function previous(): self
    {
        // Whole microseconds: Carbon's diffInSeconds() is a float that would
        // carry the trailing .999999 of `to` and shift the window by a day.
        $length = (int) $this->from->diffInMicroseconds($this->to, true) + 1;

        return new self($this->key, $this->from->subMicroseconds($length), $this->from->subMicrosecond());
    }

    public function toArray(string $timezone): array
    {
        return [
            'key' => $this->key,
            'from' => $this->from->setTimezone($timezone)->toDateString(),
            'to' => $this->to->setTimezone($timezone)->toDateString(),
        ];
    }
}
