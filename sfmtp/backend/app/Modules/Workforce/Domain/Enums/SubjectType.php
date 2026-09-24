<?php

namespace App\Modules\Workforce\Domain\Enums;

/** What an activity is about. Crops and Livestock register how to find their subjects. */
enum SubjectType: string
{
    case CropCycle = 'crop_cycle';
    case Plot = 'plot';
    case Location = 'location';
    case Animal = 'animal';
    case AnimalGroup = 'animal_group';
    case General = 'general';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
