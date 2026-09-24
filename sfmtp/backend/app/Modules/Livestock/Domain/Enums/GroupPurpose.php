<?php

namespace App\Modules\Livestock\Domain\Enums;

enum GroupPurpose: string
{
    case Dairy = 'dairy';
    case Beef = 'beef';
    case Meat = 'meat';
    case Layers = 'layers';
    case Broilers = 'broilers';
    case Breeding = 'breeding';
    case Mixed = 'mixed';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
