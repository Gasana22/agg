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

    /** The equal-length period immediately before this one (for deltas). */
    public function previous(): self
    {
        $seconds = $this->to->diffInSeconds($this->from, true) + 1;

        return new self($this->key, $this->from->subSeconds($seconds), $this->from->subSecond());
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
