<?php

namespace App\Modules\Crops\Domain\Enums;

enum OperationStatus: string
{
    case Recorded = 'recorded';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
