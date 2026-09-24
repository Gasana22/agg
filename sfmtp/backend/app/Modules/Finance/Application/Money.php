<?php

namespace App\Modules\Finance\Application;

/**
 * Amounts as decimal strings with two places, computed in integer cents so
 * sums are exact (no bcmath on every host).
 */
final class Money
{
    public static function cents(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function of(string|int|float $amount): string
    {
        return self::fromCents(self::cents($amount));
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
