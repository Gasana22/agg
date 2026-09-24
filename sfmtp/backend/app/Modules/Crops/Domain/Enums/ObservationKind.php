<?php

namespace App\Modules\Crops\Domain\Enums;

enum ObservationKind: string
{
    case Pest = 'pest';
    case Disease = 'disease';
    case Weed = 'weed';
    case Nutrient = 'nutrient';
    case Water = 'water';
    case Growth = 'growth';
    case Weather = 'weather';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
