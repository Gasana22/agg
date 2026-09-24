<?php

namespace App\Modules\Workforce\Domain\Enums;

/** Where an attendance record came from: the worker's phone, the web, or a manual entry by a manager. */
enum AttendanceSource: string
{
    case Mobile = 'mobile';
    case Web = 'web';
    case Manual = 'manual';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
