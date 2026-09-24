<?php

namespace App\Modules\Crops\Domain\Enums;

enum CloseReason: string
{
    case Harvested = 'harvested';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
