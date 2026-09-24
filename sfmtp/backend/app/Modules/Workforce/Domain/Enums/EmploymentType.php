<?php

namespace App\Modules\Workforce\Domain\Enums;

/** How a worker is engaged; casual and seasonal workers are often paid by the day. */
enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Casual = 'casual';
    case Contract = 'contract';
    case Seasonal = 'seasonal';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
