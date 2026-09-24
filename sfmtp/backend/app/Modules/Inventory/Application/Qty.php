<?php

namespace App\Modules\Inventory\Application;

/** Quantities as integer thousandths, so stock sums are exact. */
final class Qty
{
    public static function milli(string|int|float|null $q): int
    {
        return (int) round(((float) ($q ?? 0)) * 1000);
    }

    public static function of(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);

        return $sign.intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }
}
