<?php

namespace App\Modules\FarmStructure\Domain\Enums;

enum LandUse: string
{
    case Crop = 'crop';
    case Pasture = 'pasture';
    case Fallow = 'fallow';
    case Orchard = 'orchard';
    case Forestry = 'forestry';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
