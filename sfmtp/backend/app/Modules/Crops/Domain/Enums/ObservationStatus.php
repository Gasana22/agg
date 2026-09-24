<?php

namespace App\Modules\Crops\Domain\Enums;

enum ObservationStatus: string
{
    case Open = 'open';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
