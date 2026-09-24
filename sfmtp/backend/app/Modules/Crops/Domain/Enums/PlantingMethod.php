<?php

namespace App\Modules\Crops\Domain\Enums;

enum PlantingMethod: string
{
    case Direct = 'direct';
    case Transplant = 'transplant';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
