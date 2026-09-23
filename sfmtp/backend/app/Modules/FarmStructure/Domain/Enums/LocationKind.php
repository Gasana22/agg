<?php

namespace App\Modules\FarmStructure\Domain\Enums;

enum LocationKind: string
{
    case Store = 'store';
    case Building = 'building';
    case Paddock = 'paddock';
    case Housing = 'housing';
    case Water = 'water';
    case Gate = 'gate';
    case Office = 'office';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
