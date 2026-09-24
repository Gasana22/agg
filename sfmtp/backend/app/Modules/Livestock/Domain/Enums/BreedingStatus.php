<?php

namespace App\Modules\Livestock\Domain\Enums;

enum BreedingStatus: string
{
    case Served = 'served';
    case Pregnant = 'pregnant';
    case NotPregnant = 'not_pregnant';
    case Delivered = 'delivered';
    case Aborted = 'aborted';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
