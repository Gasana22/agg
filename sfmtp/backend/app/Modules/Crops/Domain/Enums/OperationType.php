<?php

namespace App\Modules\Crops\Domain\Enums;

enum OperationType: string
{
    case LandPreparation = 'land_preparation';
    case Planting = 'planting';
    case Weeding = 'weeding';
    case Fertilizing = 'fertilizing';
    case Spraying = 'spraying';
    case Irrigation = 'irrigation';
    case Scouting = 'scouting';
    case Pruning = 'pruning';
    case Thinning = 'thinning';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
