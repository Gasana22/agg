<?php

namespace App\Modules\Livestock\Domain\Enums;

enum WeightMethod: string
{
    case Scale = 'scale';
    case Tape = 'tape';
    case Estimate = 'estimate';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
