<?php

namespace App\Modules\Livestock\Domain\Enums;

enum Origin: string
{
    case Born = 'born';
    case Purchased = 'purchased';
    case Gifted = 'gifted';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
