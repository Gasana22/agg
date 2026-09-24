<?php

namespace App\Modules\Livestock\Domain\Enums;

enum HealthKind: string
{
    case Treatment = 'treatment';
    case Vaccination = 'vaccination';
    case Deworming = 'deworming';
    case Checkup = 'checkup';
    case Injury = 'injury';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
